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

class RulesVisitor extends NodeVisitorAbstract
{
    private array $rules = [];

    private bool $inRulesMethod = false;

    private array $currentRules = [];

    /**
     * Cache for parsed FormRequest rules to avoid redundant parsing
     */
    private static array $formRequestCache = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\ClassMethod && $node->name->toString() === 'rules') {
            $this->inRulesMethod = true;

            return;
        }

        if (! $this->inRulesMethod) {
            return;
        }

        if ($node instanceof Stmt\Return_) {
            $this->extractRulesFromReturn($node);
            $this->inRulesMethod = false;
        }
    }

    private function extractRulesFromReturn(Stmt\Return_ $return): void
    {
        if (! $return->expr) {
            return;
        }

        if ($return->expr instanceof Expr\Array_) {
            $this->extractRulesFromArray($return->expr);
        }

        // Handle array_merge or array spread
        if ($return->expr instanceof Expr\FuncCall) {
            $this->extractRulesFromFunctionCall($return->expr);
        }
    }

    /**
     * Extract rules from function calls like array_merge($parentRules, [...])
     */
    private function extractRulesFromFunctionCall(Expr\FuncCall $call): void
    {
        $functionName = $call->name instanceof Node\Name ? $call->name->toString() : null;

        if ($functionName === 'array_merge') {
            foreach ($call->args as $arg) {
                if ($arg->value instanceof Expr\Array_) {
                    $this->extractRulesFromArray($arg->value);
                }
            }
        }
    }

    private function extractRulesFromArray(Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            if (! $item instanceof Expr\ArrayItem || ! $item->key) {
                continue;
            }

            $fieldName = $this->extractFieldName($item->key);
            $rules = $this->extractFieldRules($item->value);

            if ($fieldName && ! empty($rules)) {
                $this->rules[$fieldName] = $rules;
            }
        }
    }

    private function extractFieldName(Expr $key): ?string
    {
        if ($key instanceof Node\Scalar\String_) {
            return $key->value;
        }

        if ($key instanceof Node\Identifier) {
            return $key->toString();
        }

        return null;
    }

    private function extractFieldRules(Expr $value): array
    {
        $rules = [];

        if ($value instanceof Node\Scalar\String_) {
            $rules = explode('|', $value->value);
        } elseif ($value instanceof Expr\Array_) {
            foreach ($value->items as $ruleItem) {
                if ($ruleItem instanceof Expr\ArrayItem) {
                    $ruleValue = $this->extractRuleValue($ruleItem->value);
                    if ($ruleValue) {
                        $rules[] = $ruleValue;
                    }
                }
            }
        }

        return $rules;
    }

    private function extractRuleValue(Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        }

        if ($expr instanceof Node\Expr\ClassConstFetch) {
            $class = $expr->class instanceof Node\Name ? $expr->class->toString() : '';
            $constant = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

            return "{$class}::{$constant}";
        }

        // Handle Rule objects like Rule::exists(), Rule::unique()
        if ($expr instanceof Expr\StaticCall) {
            return $this->extractRuleFromStaticCall($expr);
        }

        // Handle new Rule\... instances
        if ($expr instanceof Expr\New_) {
            return $this->extractRuleFromNewInstance($expr);
        }

        return null;
    }

    /**
     * Extract rule from static calls like Rule::exists('table', 'column')
     */
    private function extractRuleFromStaticCall(Expr\StaticCall $call): ?string
    {
        $class = $call->class instanceof Node\Name ? $call->class->toString() : '';
        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

        if (empty($class) || empty($method)) {
            return null;
        }

        // Common Rule facade methods
        $ruleMap = [
            'exists' => 'exists',
            'unique' => 'unique',
            'in' => 'in',
            'notIn' => 'not_in',
            'requiredIf' => 'required_if',
            'excludeIf' => 'exclude_if',
            'prohibitedIf' => 'prohibited_if',
            'dimensions' => 'dimensions',
            'imageFile' => 'image',
            'file' => 'file',
            'enum' => 'enum',
        ];

        if (isset($ruleMap[$method])) {
            $args = $this->extractCallArguments($call->args);
            if (! empty($args)) {
                return $ruleMap[$method] . ':' . implode(',', $args);
            }
            return $ruleMap[$method];
        }

        return "{$class}::{$method}";
    }

    /**
     * Extract rule from new instances like new Exists('table', 'column')
     */
    private function extractRuleFromNewInstance(Expr\New_ $new): ?string
    {
        if (! $new->class instanceof Node\Name) {
            return null;
        }

        $className = $new->class->toString();
        $shortName = class_basename($className);

        $args = $this->extractCallArguments($new->args);

        return strtolower($shortName) . (! empty($args) ? ':' . implode(',', $args) : '');
    }

    /**
     * Extract string arguments from a call
     */
    private function extractCallArguments(array $args): array
    {
        $result = [];

        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $value = $arg->value;
                if ($value instanceof Node\Scalar\String_) {
                    $result[] = $value->value;
                } elseif ($value instanceof Node\Scalar\LNumber) {
                    $result[] = (string) $value->value;
                }
            }
        }

        return $result;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Resolve and parse a FormRequest class to extract its validation rules.
     * This is the primary method for FormRequest support.
     *
     * @param string $formRequestClass Fully qualified FormRequest class name
     * @return array Parsed validation rules
     */
    public static function resolveFormRequestRules(string $formRequestClass): array
    {
        // Check cache first
        if (isset(self::$formRequestCache[$formRequestClass])) {
            return self::$formRequestCache[$formRequestClass];
        }

        if (! class_exists($formRequestClass)) {
            return [];
        }

        // Ensure it's a FormRequest
        if (! is_subclass_of($formRequestClass, \Illuminate\Foundation\Http\FormRequest::class)) {
            return [];
        }

        $rules = [];

        // Try runtime extraction first (more accurate)
        $rules = self::extractRulesAtRuntime($formRequestClass);

        // Fallback to AST parsing if runtime fails
        if (empty($rules)) {
            $rules = self::extractRulesFromAst($formRequestClass);
        }

        // Cache the result
        self::$formRequestCache[$formRequestClass] = $rules;

        return $rules;
    }

    /**
     * Try to extract rules by instantiating the FormRequest
     */
    private static function extractRulesAtRuntime(string $formRequestClass): array
    {
        try {
            $reflection = new ReflectionClass($formRequestClass);

            if (! $reflection->hasMethod('rules')) {
                return [];
            }

            $method = $reflection->getMethod('rules');

            // Skip if method requires parameters
            if ($method->getNumberOfRequiredParameters() > 0) {
                return [];
            }

            // Create instance without constructor
            $instance = $reflection->newInstanceWithoutConstructor();

            // Try to invoke rules()
            $rules = $method->invoke($instance);

            if (is_array($rules)) {
                return self::normalizeRules($rules);
            }
        } catch (\Exception $e) {
            // Fallback to AST parsing
        }

        return [];
    }

    /**
     * Extract rules by parsing the FormRequest source code with AST
     */
    private static function extractRulesFromAst(string $formRequestClass): array
    {
        try {
            $reflection = new ReflectionClass($formRequestClass);
            $filePath = $reflection->getFileName();

            if (! $filePath || ! file_exists($filePath)) {
                return [];
            }

            $code = file_get_contents($filePath);
            $parser = (new ParserFactory)->createForHostVersion();
            $ast = $parser->parse($code);

            if (! $ast) {
                return [];
            }

            $nodeFinder = new NodeFinder;

            // Find the rules method
            $rulesMethod = $nodeFinder->findFirst($ast, function (Node $node) {
                return $node instanceof Stmt\ClassMethod 
                    && $node->name->toString() === 'rules';
            });

            if (! $rulesMethod) {
                return [];
            }

            // Use the visitor to parse the rules
            $visitor = new self;
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse([$rulesMethod]);

            return $visitor->getRules();

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Normalize rules to a consistent array format
     */
    private static function normalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                $normalized[$field] = explode('|', $rule);
            } elseif (is_array($rule)) {
                $normalized[$field] = array_map(function ($r) {
                    if (is_object($r)) {
                        return (string) $r;
                    }
                    return $r;
                }, $rule);
            } elseif (is_object($rule)) {
                $normalized[$field] = [(string) $rule];
            } else {
                $normalized[$field] = [$rule];
            }
        }

        return $normalized;
    }

    /**
     * Clear the FormRequest cache (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$formRequestCache = [];
    }
}
