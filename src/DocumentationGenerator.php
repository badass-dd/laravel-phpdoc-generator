<?php

namespace Badass\LazyDocs;

use Badass\LazyDocs\Exceptions\AnalysisException;
use Badass\LazyDocs\Exceptions\GenerationException;
use Badass\LazyDocs\Generators\ScribeGenerator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class DocumentationGenerator
{
    private string $controllerClass;

    private ReflectionClass $reflection;

    private array $ast = [];

    private NodeFinder $nodeFinder;

    private array $config;

    private array $analysis = [];

    private array $existingDocBlocks = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->nodeFinder = new NodeFinder;
    }

    public function analyze(string $controllerClass, ?string $methodName = null): self
    {
        $this->controllerClass = $controllerClass;

        if (! class_exists($controllerClass)) {
            throw AnalysisException::controllerNotFound($controllerClass);
        }

        $this->reflection = new ReflectionClass($controllerClass);

        if (! $this->reflection->isInstantiable() || $this->reflection->isAbstract()) {
            throw AnalysisException::controllerNotFound($controllerClass);
        }

        $this->parseSourceCode();
        $this->extractExistingDocumentation();

        if ($methodName) {
            $this->analyzeSingleMethod($methodName);
        } else {
            $this->analyzeAllMethods();
        }

        return $this;
    }

    private function parseSourceCode(): void
    {
        $filePath = $this->reflection->getFileName();

        if (! $filePath || ! file_exists($filePath)) {
            throw AnalysisException::parseError(
                $this->controllerClass,
                'Cannot locate source file.'
            );
        }

        $code = file_get_contents($filePath);
        $parser = (new ParserFactory)->createForHostVersion();

        try {
            $this->ast = $parser->parse($code);

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $this->ast = $traverser->traverse($this->ast);

        } catch (\Exception $e) {
            throw AnalysisException::parseError($filePath, $e->getMessage());
        }
    }

    private function analyzeSingleMethod(string $methodName): void
    {
        if (! $this->reflection->hasMethod($methodName)) {
            throw AnalysisException::methodNotFound($this->controllerClass, $methodName);
        }

        $methodReflection = $this->reflection->getMethod($methodName);

        // When a specific method is requested, only check basic constraints (public, not constructor)
        // Skip complexity/resource method checks since user explicitly wants this method
        if (! $methodReflection->isPublic() || $methodReflection->isConstructor() || $methodReflection->isDestructor()) {
            return;
        }

        if (str_starts_with($methodReflection->getName(), '__')) {
            return;
        }

        $methodNode = $this->findMethodNode($methodName);

        if (! $methodNode) {
            throw AnalysisException::astError("Method node not found in AST for {$methodName}");
        }

        $this->analysis[$methodName] = $this->analyzeMethod($methodReflection, $methodNode);
    }

    private function analyzeAllMethods(): void
    {
        foreach ($this->reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->shouldAnalyzeMethod($method)) {
                $methodNode = $this->findMethodNode($method->getName());

                if ($methodNode) {
                    $this->analysis[$method->getName()] = $this->analyzeMethod($method, $methodNode);
                }
            }
        }
    }

    private function analyzeMethod(ReflectionMethod $method, Node\Stmt\ClassMethod $methodNode): array
    {
        $methodAnalysis = [
            'name' => $method->getName(),
            'visibility' => $method->getModifiers(),
            'parameters' => $this->analyzeParameters($method, $methodNode),
            'return_type' => $this->analyzeReturnType($method, $methodNode),
            'body' => $this->analyzeMethodBody($methodNode),
            'complexity' => $this->calculateComplexity($methodNode),
            'existing_doc' => $this->existingDocBlocks[$method->getName()] ?? null,
        ];

        $methodName = $method->getName();

        $analyzers = [
            new Analyzers\FormRequestAnalyzer($methodAnalysis['parameters'], $this->config),
            new Analyzers\ResponseAnalyzer($this->ast, $this->controllerClass, $methodName, $this->config),
            new Analyzers\InlineValidationAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\ExceptionAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\ModelAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\DatabaseAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\AuthorizationAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\CacheAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\JobAnalyzer($this->ast, $this->controllerClass, $methodName),
            new Analyzers\MiddlewareAnalyzer($this->ast, $this->controllerClass, $methodName),
        ];

        foreach ($analyzers as $analyzer) {
            $methodAnalysis = array_merge_recursive($methodAnalysis, $analyzer->analyze());
        }

        return $methodAnalysis;
    }

    private function findMethodNode(string $methodName): ?Node\Stmt\ClassMethod
    {
        return $this->nodeFinder->findFirst($this->ast, function (Node $node) use ($methodName) {
            return $node instanceof Node\Stmt\ClassMethod &&
                   $node->name->toString() === $methodName;
        });
    }

    private function analyzeParameters(ReflectionMethod $method, Node\Stmt\ClassMethod $methodNode): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'type' => $this->extractParameterType($param),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                'is_optional' => $param->isOptional(),
                'is_variadic' => $param->isVariadic(),
            ];

            if ($param->getType() && ! $param->getType()->isBuiltin()) {
                $typeName = $param->getType()->getName();

                if (is_subclass_of($typeName, \Illuminate\Foundation\Http\FormRequest::class)) {
                    $paramInfo['form_request'] = true;
                    $paramInfo['request_class'] = $typeName;
                }
            }

            $parameters[] = $paramInfo;
        }

        return $parameters;
    }

    private function extractParameterType(ReflectionParameter $param): string
    {
        $type = $param->getType();

        if (! $type) {
            return 'mixed';
        }

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            $types = array_map(fn ($t) => $t->getName(), $type->getTypes());

            return implode('|', $types);
        }

        return 'mixed';
    }

    private function analyzeReturnType(ReflectionMethod $method, Node\Stmt\ClassMethod $methodNode): array
    {
        $returnType = $method->getReturnType();

        $analysis = [
            'type' => $returnType ? $returnType->getName() : 'mixed',
            'nullable' => $returnType && $returnType->allowsNull(),
            'is_builtin' => $returnType && $returnType->isBuiltin(),
        ];

        $returnVisitor = new Visitors\ReturnVisitor;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($returnVisitor);
        $traverser->traverse([$methodNode]);

        $analysis['returns'] = $returnVisitor->getReturns();

        return $analysis;
    }

    private function analyzeMethodBody(Node\Stmt\ClassMethod $methodNode): array
    {
        if (! $methodNode->stmts) {
            return ['empty' => true];
        }

        $bodyVisitor = new Visitors\MethodBodyVisitor($this->config);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($bodyVisitor);
        $traverser->traverse($methodNode->stmts);

        return [
            'operations' => $bodyVisitor->getOperations(),
            'variables' => $bodyVisitor->getVariables(),
            'calls' => $bodyVisitor->getMethodCalls(),
            'conditions' => $bodyVisitor->getConditions(),
            'loops' => $bodyVisitor->getLoops(),
        ];
    }

    private function calculateComplexity(Node\Stmt\ClassMethod $methodNode): array
    {
        $complexityVisitor = new Visitors\ComplexityVisitor;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($complexityVisitor);
        $traverser->traverse([$methodNode]);

        return [
            'cyclomatic' => $complexityVisitor->getCyclomaticComplexity(),
            'cognitive' => $complexityVisitor->getCognitiveComplexity(),
            'maintainability_index' => $complexityVisitor->getMaintainabilityIndex(),
            'max_nesting' => $complexityVisitor->getMaxNestingLevel(),
        ];
    }

    private function shouldAnalyzeMethod(ReflectionMethod $method): bool
    {
        if (! $method->isPublic() || $method->isConstructor() || $method->isDestructor()) {
            return false;
        }

        if (in_array($method->getName(), $this->config['exclude_methods'] ?? [])) {
            return false;
        }

        if (str_starts_with($method->getName(), '__')) {
            return false;
        }

        // Always include standard CRUD resource methods
        $resourceMethods = ['index', 'show', 'store', 'create', 'update', 'destroy', 'edit'];
        if (in_array($method->getName(), $resourceMethods)) {
            return true;
        }

        // If include_simple_methods is set (via --force), include all public methods
        if ($this->config['include_simple_methods'] ?? false) {
            return true;
        }

        $complexityThreshold = $this->config['complexity_threshold'] ?? 1;
        $methodNode = $this->findMethodNode($method->getName());

        if ($methodNode) {
            $complexity = $this->calculateComplexity($methodNode);
            if ($complexity['cyclomatic'] < $complexityThreshold) {
                return false;
            }
        }

        return true;
    }

    private function extractExistingDocumentation(): void
    {
        foreach ($this->reflection->getMethods() as $method) {
            $docComment = $method->getDocComment();
            if ($docComment) {
                $this->existingDocBlocks[$method->getName()] = $docComment;
            }
        }
    }

    public function generateForMethod(string $methodName): string
    {
        if (! isset($this->analysis[$methodName])) {
            throw GenerationException::methodNotAnalyzed($methodName);
        }

        $generator = new ScribeGenerator(
            $this->analysis[$methodName],
            $this->controllerClass,
            $methodName,
            $this->config,
            $this->existingDocBlocks[$methodName] ?? null
        );

        return $generator->generate();
    }

    /**
     * Generate documentation for a method using externally enhanced analysis data.
     * This allows AnalysisEngine to provide enhanced analysis (with response examples, etc.)
     * that gets passed directly to ScribeGenerator.
     */
    public function generateForMethodWithAnalysis(string $methodName, array $enhancedAnalysis): string
    {
        $generator = new ScribeGenerator(
            $enhancedAnalysis,
            $this->controllerClass,
            $methodName,
            $this->config,
            $this->existingDocBlocks[$methodName] ?? null
        );

        return $generator->generate();
    }

    public function generateForAll(): array
    {
        $results = [];

        foreach (array_keys($this->analysis) as $methodName) {
            $results[$methodName] = $this->generateForMethod($methodName);
        }

        return $results;
    }

    public function getAnalysis(): array
    {
        return $this->analysis;
    }

    public function getControllerClass(): string
    {
        return $this->controllerClass;
    }
}
