<?php

namespace Badass\LazyDocs\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

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
    ];

    private array $variables = [];

    private array $methodCalls = [];

    private array $conditions = [];

    private array $loops = [];

    private array $config;

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

        if ($node instanceof Stmt\Throw_) {
            $this->analyzeThrow($node);
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
                        if ($r->value instanceof Node\Scalar\String_) {
                            $rules[] = $r->value->value;
                        } else {
                            $rules[] = $printer->prettyPrintExpr($r->value);
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

    private function analyzeThrow(Stmt\Throw_ $throw): void
    {
        if ($throw->expr instanceof Expr\New_) {
            $exceptionClass = $throw->expr->class instanceof Node\Name
                ? $throw->expr->class->toString()
                : 'UnknownException';

            $this->operations['exceptions'][] = [
                'exception' => $exceptionClass,
                'arguments' => $this->extractArguments($throw->expr->args),
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
