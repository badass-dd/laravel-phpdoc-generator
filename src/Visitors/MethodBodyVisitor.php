<?php

namespace Badass\LazyDocs\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;

class MethodBodyVisitor extends NodeVisitorAbstract
{
    private array $operations = [
        'database' => [],
        'cache' => [],
        'jobs' => [],
        'responses' => [],
        'exceptions' => [],
        'authorization' => [],
        'validation' => [],
        'middleware' => [],
        'eager_relations' => [],
        'body_params' => [],
        'api_resources' => [],  // Track API Resource usage
    ];

    private array $variables = [];

    private array $methodCalls = [];

    private array $conditions = [];

    private array $loops = [];

    private array $config;

    /**
     * Cache for parsed API Resource structures
     */
    private static array $resourceCache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Expression && $node->expr instanceof Expr) {
            $this->analyzeExpression($node->expr);
        }

        if ($node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_) {
            $this->conditions[] = $this->extractConditionInfo($node);
        }

        if ($node instanceof Stmt\Foreach_ || $node instanceof Stmt\For_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_) {
            $this->loops[] = get_class($node);
        }

        if ($node instanceof Stmt\Return_) {
            $this->analyzeReturn($node);
        }

        // Handle throw statements (PHP-Parser compatibility)
        if ($node instanceof Stmt\Expression && $node->expr instanceof Expr\Throw_) {
            $this->analyzeThrowExpression($node->expr);
        }

        return null;
    }

    private function analyzeExpression(Expr $expr): void
    {
        if ($expr instanceof Expr\StaticCall) {
            $this->analyzeStaticCall($expr);
        }

        if ($expr instanceof Expr\MethodCall) {
            $this->analyzeMethodCall($expr);
        }

        if ($expr instanceof Expr\FuncCall) {
            $this->analyzeFunctionCall($expr);
        }

        if ($expr instanceof Expr\Assign) {
            $this->analyzeAssignment($expr);
        }

        // Detect API Resource instantiation (new UserResource(...))
        if ($expr instanceof Expr\New_) {
            $this->analyzeNewExpression($expr);
        }
    }

    private function analyzeStaticCall(Expr\StaticCall $call): void
    {
        $className = $call->class instanceof Node\Name ? $call->class->toString() : null;
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

        if (! $className || ! $methodName) {
            return;
        }

        $callInfo = [
            'type' => 'static',
            'class' => $className,
            'method' => $methodName,
            'arguments' => $this->extractArguments($call->args),
        ];

        $this->methodCalls[] = $callInfo;

        // Get the short class name for comparison
        $shortClassName = basename(str_replace('\\', '/', $className));

        if (($shortClassName === 'DB' || $className === 'Illuminate\\Support\\Facades\\DB') && $methodName === 'transaction') {
            $this->operations['database'][] = 'transaction';
            $this->operations['database']['transaction'] = true;
        } elseif (($shortClassName === 'DB' || $className === 'Illuminate\\Support\\Facades\\DB') && $methodName === 'beginTransaction') {
            $this->operations['database']['transaction'] = true;
        } elseif (($shortClassName === 'Validator' || $className === 'Illuminate\\Support\\Facades\\Validator') && $methodName === 'make') {
            // Extract validation rules from Validator::make($request->all(), [...rules...])
            $this->extractValidatorMakeRules($call->args);
        } elseif (str_contains($className, 'Cache') || $methodName === 'cache') {
            $this->operations['cache'][] = $methodName;
        } elseif (str_contains($className, 'Log')) {
            $this->operations['logging'][] = $methodName;
        } elseif (class_exists($className) && method_exists($className, 'dispatch')) {
            $this->operations['jobs'][] = $className;
        } elseif ($methodName === 'with') {
            // Static with() like Model::with('relation')->get()
            $relations = $this->extractEagerRelations($call->args);
            foreach ($relations as $relation) {
                $this->operations['eager_relations'][] = $relation;
            }
        }

        // Detect API Resource static calls like UserResource::collection($users)
        if ($methodName === 'collection' && $this->isApiResourceClass($className)) {
            $resourceInfo = $this->parseApiResourceClass($className);
            if ($resourceInfo) {
                $resourceInfo['is_collection'] = true;
                $this->operations['api_resources'][] = $resourceInfo;
            }
        }
    }

    /**
     * Analyze new expressions to detect API Resource instantiation
     */
    private function analyzeNewExpression(Expr\New_ $new): void
    {
        if (! $new->class instanceof Node\Name) {
            return;
        }

        $className = $new->class->toString();

        // Check if this is an API Resource class
        if ($this->isApiResourceClass($className)) {
            $resourceInfo = $this->parseApiResourceClass($className);
            if ($resourceInfo) {
                $resourceInfo['is_collection'] = false;
                $this->operations['api_resources'][] = $resourceInfo;
            }
        }
    }

    /**
     * Check if a class is a JsonResource (API Resource)
     */
    private function isApiResourceClass(string $className): bool
    {
        if (! class_exists($className)) {
            // Check if it might be a Resource based on naming convention
            return str_ends_with($className, 'Resource') 
                || str_ends_with($className, 'Collection');
        }

        return is_subclass_of($className, \Illuminate\Http\Resources\Json\JsonResource::class);
    }

    /**
     * Parse an API Resource class to extract its toArray() structure
     */
    private function parseApiResourceClass(string $className): ?array
    {
        // Check cache first
        if (isset(self::$resourceCache[$className])) {
            return self::$resourceCache[$className];
        }

        $result = [
            'class' => $className,
            'fields' => [],
            'relations' => [],
            'conditional_fields' => [],
        ];

        // Try to get the resource structure
        $fields = $this->extractResourceFields($className);
        if (! empty($fields)) {
            $result['fields'] = $fields['fields'] ?? [];
            $result['relations'] = $fields['relations'] ?? [];
            $result['conditional_fields'] = $fields['conditional_fields'] ?? [];
        }

        // Cache the result
        self::$resourceCache[$className] = $result;

        return $result;
    }

    /**
     * Extract fields from a JsonResource's toArray() method using AST parsing
     */
    private function extractResourceFields(string $className): array
    {
        $result = [
            'fields' => [],
            'relations' => [],
            'conditional_fields' => [],
        ];

        if (! class_exists($className)) {
            return $result;
        }

        try {
            $reflection = new ReflectionClass($className);
            $filePath = $reflection->getFileName();

            if (! $filePath || ! file_exists($filePath)) {
                return $result;
            }

            $code = file_get_contents($filePath);
            $parser = (new ParserFactory)->createForHostVersion();
            $ast = $parser->parse($code);

            if (! $ast) {
                return $result;
            }

            $nodeFinder = new NodeFinder;

            // Find the toArray method
            $toArrayMethod = $nodeFinder->findFirst($ast, function (Node $node) {
                return $node instanceof Stmt\ClassMethod 
                    && $node->name->toString() === 'toArray';
            });

            if (! $toArrayMethod) {
                return $result;
            }

            // Find the return statement in toArray
            /** @var Stmt\Return_|null $returnStmt */
            $returnStmt = $nodeFinder->findFirst([$toArrayMethod], function (Node $node) {
                return $node instanceof Stmt\Return_;
            });

            if (! $returnStmt instanceof Stmt\Return_ || ! $returnStmt->expr instanceof Expr\Array_) {
                return $result;
            }

            // Parse the returned array
            $this->parseResourceArrayNode($returnStmt->expr, $result);

            return $result;

        } catch (\Exception $e) {
            return $result;
        }
    }

    /**
     * Parse the array node from a Resource's toArray() method
     */
    private function parseResourceArrayNode(Expr\Array_ $array, array &$result): void
    {
        foreach ($array->items as $item) {
            if (! $item instanceof Expr\ArrayItem || ! $item->key) {
                continue;
            }

            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
            if (! $key) {
                continue;
            }

            // Check if this is a conditional field (when, whenLoaded, etc.)
            $itemValue = $item->value;
            if ($itemValue instanceof Expr\MethodCall) {
                $methodName = $itemValue->name instanceof Node\Identifier 
                    ? $itemValue->name->toString() 
                    : null;

                if (in_array($methodName, ['when', 'whenLoaded', 'whenPivotLoaded', 'whenNotNull'])) {
                    if ($methodName === 'whenLoaded') {
                        // This is a relation
                        $result['relations'][$key] = [
                            'name' => $key,
                            'conditional' => true,
                            'type' => 'whenLoaded',
                        ];
                    } else {
                        $result['conditional_fields'][$key] = [
                            'name' => $key,
                            'condition' => $methodName,
                        ];
                    }
                    continue;
                }
            }

            // Check for nested resource
            if ($itemValue instanceof Expr\New_) {
                $nestedClass = $itemValue->class instanceof Node\Name 
                    ? $itemValue->class->toString() 
                    : null;

                if ($nestedClass && $this->isApiResourceClass($nestedClass)) {
                    $result['relations'][$key] = [
                        'name' => $key,
                        'resource' => $nestedClass,
                        'conditional' => false,
                    ];
                    continue;
                }
            }

            // Check for static collection call
            if ($itemValue instanceof Expr\StaticCall) {
                $nestedClass = $itemValue->class instanceof Node\Name 
                    ? $itemValue->class->toString() 
                    : null;
                $staticMethod = $itemValue->name instanceof Node\Identifier 
                    ? $itemValue->name->toString() 
                    : null;

                if ($nestedClass && $staticMethod === 'collection' && $this->isApiResourceClass($nestedClass)) {
                    $result['relations'][$key] = [
                        'name' => $key,
                        'resource' => $nestedClass,
                        'is_collection' => true,
                        'conditional' => false,
                    ];
                    continue;
                }
            }

            // Regular field - extract the type if possible
            $fieldType = $this->inferFieldTypeFromValue($itemValue);
            $result['fields'][$key] = [
                'name' => $key,
                'type' => $fieldType,
            ];
        }
    }

    /**
     * Infer the type of a field from its value expression
     */
    private function inferFieldTypeFromValue(Expr $value): string
    {
        if ($value instanceof Node\Scalar\String_) {
            return 'string';
        }

        if ($value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\LNumber) {
            return 'integer';
        }

        if ($value instanceof Node\Scalar\DNumber) {
            return 'float';
        }

        if ($value instanceof Expr\ConstFetch) {
            $name = strtolower($value->name->toString());
            if (in_array($name, ['true', 'false'])) {
                return 'boolean';
            }
            if ($name === 'null') {
                return 'null';
            }
        }

        if ($value instanceof Expr\Array_) {
            return 'array';
        }

        // $this->id, $this->name patterns
        if ($value instanceof Expr\PropertyFetch) {
            $property = $value->name instanceof Node\Identifier ? $value->name->toString() : '';
            return $this->inferTypeFromPropertyName($property);
        }

        return 'mixed';
    }

    /**
     * Infer type from property name patterns
     */
    private function inferTypeFromPropertyName(string $name): string
    {
        $nameLower = strtolower($name);

        if ($name === 'id' || str_ends_with($nameLower, '_id')) {
            return 'integer';
        }

        if (str_contains($nameLower, 'at') || str_contains($nameLower, 'date')) {
            return 'datetime';
        }

        if (str_starts_with($nameLower, 'is_') || str_starts_with($nameLower, 'has_')) {
            return 'boolean';
        }

        if (str_contains($nameLower, 'price') || str_contains($nameLower, 'amount') || str_contains($nameLower, 'total')) {
            return 'float';
        }

        if (str_contains($nameLower, 'count') || str_contains($nameLower, 'quantity')) {
            return 'integer';
        }

        return 'mixed';
    }

    /**
     * Get parsed API resources from the method
     */
    public function getApiResources(): array
    {
        return $this->operations['api_resources'] ?? [];
    }

    /**
     * Clear the resource cache (useful for testing)
     */
    public static function clearResourceCache(): void
    {
        self::$resourceCache = [];
    }

    private function analyzeMethodCall(Expr\MethodCall $call): void
    {
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

        if (! $methodName) {
            return;
        }

        $varType = $this->inferVariableType($call->var);

        $callInfo = [
            'type' => 'instance',
            'method' => $methodName,
            'variable_type' => $varType,
            'arguments' => $this->extractArguments($call->args),
        ];

        $this->methodCalls[] = $callInfo;

        if (in_array($methodName, ['authorize', 'can', 'cannot'])) {
            $this->operations['authorization'][] = $methodName;
        } elseif (in_array($methodName, ['validate', 'validateWithBag'])) {
            $this->operations['validation'][] = $methodName;
        } elseif (in_array($methodName, ['json', 'download', 'file', 'stream'])) {
            $this->operations['responses'][] = $methodName;
        } elseif (in_array($methodName, ['with', 'load'])) {
            // Track eager-loaded relations
            $relations = $this->extractEagerRelations($call->args);
            foreach ($relations as $relation) {
                $this->operations['eager_relations'][] = $relation;
            }
        }
    }

    /**
     * Extract relation names from with() or load() arguments
     */
    private function extractEagerRelations(array $args): array
    {
        $relations = [];

        foreach ($args as $arg) {
            if ($arg->value instanceof Node\Scalar\String_) {
                $relations[] = $arg->value->value;
            } elseif ($arg->value instanceof Node\Expr\Array_) {
                foreach ($arg->value->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_) {
                        $relations[] = $item->key->value;
                    } elseif ($item->value instanceof Node\Scalar\String_) {
                        $relations[] = $item->value->value;
                    }
                }
            }
        }

        return $relations;
    }

    /**
     * Extract validation rules from Validator::make($request->all(), [...rules...])
     */
    private function extractValidatorMakeRules(array $args): void
    {
        // Second argument contains the rules
        if (! isset($args[1])) {
            return;
        }

        $rulesArg = $args[1]->value ?? null;
        if (! $rulesArg instanceof Node\Expr\Array_) {
            return;
        }

        foreach ($rulesArg->items as $item) {
            if (! $item instanceof Node\Expr\ArrayItem) {
                continue;
            }

            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
            if (! $key) {
                continue;
            }

            $rules = [];
            $value = $item->value;

            if ($value instanceof Node\Scalar\String_) {
                $rules = explode('|', $value->value);
            } elseif ($value instanceof Node\Expr\Array_) {
                $printer = new \PhpParser\PrettyPrinter\Standard;
                foreach ($value->items as $r) {
                    if ($r instanceof Node\Expr\ArrayItem) {
                        $ruleValue = $r->value;
                        if ($ruleValue instanceof Node\Scalar\String_) {
                            $rules[] = $ruleValue->value;
                        } else {
                            $rules[] = $printer->prettyPrintExpr($ruleValue);
                        }
                    }
                }
            }

            $this->operations['body_params'][$key] = [
                'field' => $key,
                'type' => $this->inferTypeFromValidationRules($rules),
                'required' => $this->isFieldRequired($rules),
                'rules' => $rules,
                'description' => $this->generateDescriptionFromField($key, $rules),
            ];
        }
    }

    /**
     * Infer PHP type from Laravel validation rules
     */
    private function inferTypeFromValidationRules(array $rules): string
    {
        foreach ($rules as $rule) {
            if (str_contains($rule, 'integer') || str_contains($rule, 'numeric')) {
                return 'integer';
            }
            if (str_contains($rule, 'array')) {
                return 'array';
            }
            if (str_contains($rule, 'boolean')) {
                return 'boolean';
            }
            if (str_contains($rule, 'date') || str_contains($rule, 'date_format')) {
                return 'string';
            }
            if (str_contains($rule, 'email')) {
                return 'string';
            }
            if (str_contains($rule, 'url')) {
                return 'string';
            }
        }

        return 'string';
    }

    /**
     * Check if field is required based on validation rules
     */
    private function isFieldRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'required')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate description from field name and rules
     */
    private function generateDescriptionFromField(string $field, array $rules): string
    {
        $description = ucfirst(str_replace(['_', '.'], ' ', $field));

        // Add constraints from rules
        $constraints = [];
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'max:')) {
                $constraints[] = 'Maximum '.substr($rule, 4).' characters';
            }
            if (str_starts_with($rule, 'min:')) {
                $constraints[] = 'Minimum '.substr($rule, 4).' characters';
            }
            if (str_starts_with($rule, 'exists:')) {
                $table = explode(',', substr($rule, 7))[0];
                $constraints[] = "Must exist in {$table}";
            }
            if (str_starts_with($rule, 'date_format:')) {
                $format = substr($rule, 12);
                $constraints[] = "Format: {$format}";
            }
        }

        if (! empty($constraints)) {
            $description .= '. '.implode('. ', $constraints);
        }

        return $description;
    }

    private function analyzeFunctionCall(Expr\FuncCall $call): void
    {
        if ($call->name instanceof Node\Name) {
            $functionName = $call->name->toString();

            if (in_array($functionName, ['abort', 'redirect', 'view', 'response'])) {
                $this->operations['responses'][] = $functionName;
            }
        }
    }

    private function analyzeAssignment(Expr\Assign $assign): void
    {
        if ($assign->var instanceof Expr\Variable && is_string($assign->var->name)) {
            $varName = $assign->var->name;
            $this->variables[$varName] = $this->extractValueInfo($assign->expr);
        }

        // Track dynamic property assignments like $model->evaluation_status = 'value'
        if ($assign->var instanceof Expr\PropertyFetch) {
            $propertyName = $this->getPropertyName($assign->var);
            if ($propertyName) {
                $this->operations['dynamic_fields'][] = [
                    'field' => $propertyName,
                    'value_type' => $this->inferValueType($assign->expr),
                ];
            }
        }

        // Also analyze the right-hand side expression for method calls
        $this->analyzeExpression($assign->expr);
    }

    /**
     * Get the property name from a PropertyFetch node
     */
    private function getPropertyName(Expr\PropertyFetch $propertyFetch): ?string
    {
        if ($propertyFetch->name instanceof \PhpParser\Node\Identifier) {
            return $propertyFetch->name->name;
        }

        return null;
    }

    /**
     * Infer the type of value being assigned
     */
    private function inferValueType(Expr $expr): string
    {
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return 'string';
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return 'integer';
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return 'float';
        }
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if (in_array($name, ['true', 'false'])) {
                return 'boolean';
            }
            if ($name === 'null') {
                return 'null';
            }
        }
        if ($expr instanceof Expr\Array_) {
            return 'array';
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\FuncCall) {
            return 'mixed';
        }

        return 'string'; // Default to string
    }

    private function analyzeReturn(Stmt\Return_ $return): void
    {
        if (! $return->expr) {
            $this->operations['responses'][] = ['type' => 'void', 'status' => null];

            return;
        }

        // Recursively analyze the return expression for method calls (like with(), load())
        $this->analyzeExpressionRecursively($return->expr);

        $responseInfo = [
            'type' => $this->determineResponseType($return->expr),
            'content' => $this->extractResponseContent($return->expr),
            'status' => $this->extractResponseStatus($return->expr),
        ];

        $this->operations['responses'][] = $responseInfo;
    }

    /**
     * Recursively analyze an expression for method/static calls
     */
    private function analyzeExpressionRecursively(Expr $expr): void
    {
        if ($expr instanceof Expr\StaticCall) {
            $this->analyzeStaticCall($expr);

            // Also analyze arguments
            foreach ($expr->args as $arg) {
                if ($arg->value instanceof Expr) {
                    $this->analyzeExpressionRecursively($arg->value);
                }
            }
        }

        if ($expr instanceof Expr\MethodCall) {
            $this->analyzeMethodCall($expr);

            // Continue analyzing the var (the object the method is called on)
            if ($expr->var instanceof Expr) {
                $this->analyzeExpressionRecursively($expr->var);
            }

            // Also analyze arguments
            foreach ($expr->args as $arg) {
                if ($arg->value instanceof Expr) {
                    $this->analyzeExpressionRecursively($arg->value);
                }
            }
        }

        if ($expr instanceof Expr\FuncCall) {
            $this->analyzeFunctionCall($expr);

            // Also analyze arguments
            foreach ($expr->args as $arg) {
                if ($arg->value instanceof Expr) {
                    $this->analyzeExpressionRecursively($arg->value);
                }
            }
        }
    }

    /**
     * Analyze throw expressions (PHP 8+ compatible)
     */
    private function analyzeThrowExpression(Expr\Throw_ $throw): void
    {
        $throwExpr = $throw->expr;
        if ($throwExpr instanceof Expr\New_) {
            $exceptionClass = $throwExpr->class instanceof Node\Name
                ? $throwExpr->class->toString()
                : 'UnknownException';

            $this->operations['exceptions'][] = [
                'exception' => $exceptionClass,
                'arguments' => $this->extractArguments($throwExpr->args),
            ];
        }
    }

    private function inferVariableType(Expr $expr): ?string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $this->variables[$expr->name]['type'] ?? null;
        }

        if ($expr instanceof Expr\PropertyFetch) {
            return 'object';
        }

        if ($expr instanceof Expr\MethodCall) {
            return 'mixed';
        }

        return null;
    }

    private function extractArguments(array $args): array
    {
        $arguments = [];

        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $arguments[] = $this->extractValueInfo($arg->value);
            }
        }

        return $arguments;
    }

    private function extractValueInfo(Expr $expr): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return ['type' => 'string', 'value' => $expr->value];
        }

        if ($expr instanceof Node\Scalar\LNumber) {
            return ['type' => 'int', 'value' => $expr->value];
        }

        if ($expr instanceof Node\Scalar\DNumber) {
            return ['type' => 'float', 'value' => $expr->value];
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return ['type' => 'constant', 'value' => $expr->name->toString()];
        }

        if ($expr instanceof Node\Expr\Array_) {
            return ['type' => 'array', 'value' => $this->extractArrayValues($expr)];
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return ['type' => 'variable', 'name' => $expr->name];
        }

        return ['type' => 'expression', 'raw' => (new \PhpParser\PrettyPrinter\Standard)->prettyPrintExpr($expr)];
    }

    private function extractArrayValues(Node\Expr\Array_ $array): array
    {
        $values = [];

        foreach ($array->items as $item) {
            if ($item instanceof Node\Expr\ArrayItem) {
                $key = $item->key ? $this->extractValueInfo($item->key) : null;
                $value = $item->value ? $this->extractValueInfo($item->value) : null;

                if ($key) {
                    $values[] = ['key' => $key, 'value' => $value];
                } else {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    private function determineResponseType(Expr $expr): string
    {
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name) {
            if ($expr->class->toString() === 'response' && $expr->name instanceof Node\Identifier) {
                return 'response';
            }
        }

        if ($expr instanceof Expr\MethodCall) {
            $methodName = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

            if (in_array($methodName, ['json', 'download', 'file', 'stream'])) {
                return 'http_response';
            }
        }

        if ($expr instanceof Expr\Variable) {
            return 'variable';
        }

        if ($expr instanceof Node\Scalar || $expr instanceof Node\Expr\Array_) {
            return 'direct';
        }

        return 'unknown';
    }

    private function extractResponseContent(Expr $expr): mixed
    {
        $printer = new \PhpParser\PrettyPrinter\Standard;

        try {
            return $printer->prettyPrintExpr($expr);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractResponseStatus(Expr $expr): ?int
    {
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name) {
            if ($expr->class->toString() === 'response' && $expr->name instanceof Node\Identifier) {
                if (isset($expr->args[1])) {
                    $arg = $expr->args[1];
                    if ($arg->value instanceof Node\Scalar\LNumber) {
                        return $arg->value->value;
                    }
                }
            }
        }

        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            if ($expr->name->toString() === 'json' && isset($expr->args[1])) {
                $arg = $expr->args[1];
                if ($arg->value instanceof Node\Scalar\LNumber) {
                    return $arg->value->value;
                }
            }
        }

        return null;
    }

    private function extractConditionInfo(Node $node): array
    {
        $printer = new \PhpParser\PrettyPrinter\Standard;

        return [
            'type' => get_class($node),
            'condition' => $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_
                ? $printer->prettyPrintExpr($node->cond)
                : null,
            'complexity' => $this->calculateConditionComplexity($node),
        ];
    }

    private function calculateConditionComplexity(Node $node): int
    {
        $complexity = 1;

        if ($node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_) {
            $condition = (new \PhpParser\PrettyPrinter\Standard)->prettyPrintExpr($node->cond);
            $complexity += substr_count($condition, '&&');
            $complexity += substr_count($condition, '||');
            $complexity += substr_count($condition, ' and ');
            $complexity += substr_count($condition, ' or ');
        }

        return $complexity;
    }

    public function getOperations(): array
    {
        return $this->operations;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getLoops(): array
    {
        return $this->loops;
    }
}
