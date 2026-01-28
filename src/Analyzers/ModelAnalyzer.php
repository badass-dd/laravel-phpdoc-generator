<?php

namespace Badass\LazyDocs\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PhpParser\Node;
use ReflectionClass;

class ModelAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $modelCalls = $this->findModelCalls($methodNode);

        $analysis = [
            'model_operations' => [],
            'model_fields' => [],
            'model_relations' => [],
            'models' => [], // For ScribeGenerator compatibility
        ];

        // Detect models from method parameters (route model binding)
        $parameterModels = $this->findModelParameters($methodNode);
        foreach ($parameterModels as $modelClass) {
            if (! isset($analysis['model_fields'][$modelClass])) {
                $modelInfo = $this->analyzeModel($modelClass);
                $analysis['model_fields'][$modelClass] = $modelInfo;
                $analysis['models'][$modelClass] = $modelInfo;
            }

            if (! isset($analysis['model_relations'][$modelClass])) {
                $analysis['model_relations'][$modelClass] = $this->analyzeModelRelations($modelClass);
            }
        }

        foreach ($modelCalls as $call) {
            $modelClass = $call['class'];
            $operation = $call['operation'];

            $analysis['model_operations'][] = [
                'model' => $modelClass,
                'operation' => $operation,
                'arguments' => $call['arguments'],
            ];

            // Analyze model structure
            if (! isset($analysis['model_fields'][$modelClass])) {
                $modelInfo = $this->analyzeModel($modelClass);
                $analysis['model_fields'][$modelClass] = $modelInfo;
                // Also add to 'models' key for ScribeGenerator
                $analysis['models'][$modelClass] = $modelInfo;
            }

            if (! isset($analysis['model_relations'][$modelClass])) {
                $analysis['model_relations'][$modelClass] = $this->analyzeModelRelations($modelClass);
            }
        }

        return $analysis;
    }

    /**
     * Find Eloquent model classes from method parameters (route model binding)
     */
    private function findModelParameters(Node\Stmt\ClassMethod $methodNode): array
    {
        $models = [];

        foreach ($methodNode->params as $param) {
            if ($param->type instanceof Node\Name) {
                $typeName = $param->type->toString();

                // Try to resolve the full class name
                $fullClassName = $this->resolveClassName($typeName);

                if ($fullClassName && class_exists($fullClassName) && is_subclass_of($fullClassName, Model::class)) {
                    $models[] = $fullClassName;
                }
            }
        }

        return $models;
    }

    /**
     * Resolve a class name to its fully qualified name
     */
    private function resolveClassName(string $className): ?string
    {
        // If it's already a FQCN
        if (class_exists($className)) {
            return $className;
        }

        // Try with App\Models namespace
        $appModels = 'App\\Models\\'.$className;
        if (class_exists($appModels)) {
            return $appModels;
        }

        // Try extracting from the controller's use statements
        // The AST should have use statements at the top level
        foreach ($this->ast as $node) {
            if ($node instanceof Node\Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $useName = $use->name->toString();
                    $alias = $use->alias ? $use->alias->toString() : class_basename($useName);

                    if ($alias === $className && class_exists($useName)) {
                        return $useName;
                    }
                }
            }
        }

        return null;
    }

    private function findModelCalls(Node\Stmt\ClassMethod $methodNode): array
    {
        $calls = [];

        $staticCalls = $this->nodeFinder->findInstanceOf($methodNode, \PhpParser\Node\Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if ($call->class instanceof \PhpParser\Node\Name) {
                $className = $call->class->toString();

                // Try to resolve the class name to its fully qualified name
                $resolvedClassName = $this->resolveClassName($className);
                $classToCheck = $resolvedClassName ?? $className;

                if (class_exists($classToCheck) && is_subclass_of($classToCheck, Model::class)) {
                    $methodName = $call->name instanceof \PhpParser\Node\Identifier ? $call->name->toString() : '';

                    // For query() calls, mark the operation as 'query' and include model info
                    $operation = $methodName;
                    if ($methodName === 'query') {
                        $operation = 'index'; // Treat query() as an index/list operation
                    } elseif ($methodName === 'with') {
                        $operation = 'index'; // with() typically means fetching collection with relations
                    } elseif ($methodName === 'all' || $methodName === 'get') {
                        $operation = 'index'; // all() and get() are collection operations
                    }

                    $calls[] = [
                        'class' => $classToCheck, // Use the resolved fully qualified class name
                        'operation' => $operation,
                        'arguments' => $this->extractCallArguments($call->args),
                    ];
                }
            }
        }

        return $calls;
    }

    private function extractCallArguments(array $args): array
    {
        $arguments = [];

        foreach ($args as $arg) {
            if ($arg instanceof \PhpParser\Node\Arg) {
                $arguments[] = $this->extractNodeValue($arg->value);
            }
        }

        return $arguments;
    }

    private function extractNodeValue(\PhpParser\Node $node): mixed
    {
        if ($node instanceof \PhpParser\Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof \PhpParser\Node\Scalar\LNumber) {
            return $node->value;
        }

        if ($node instanceof \PhpParser\Node\Scalar\DNumber) {
            return $node->value;
        }

        if ($node instanceof \PhpParser\Node\Expr\ConstFetch) {
            return $node->name->toString();
        }

        if ($node instanceof \PhpParser\Node\Expr\Array_) {
            return $this->parseArrayNode($node);
        }

        return null;
    }

    private function parseArrayNode(\PhpParser\Node\Expr\Array_ $arrayNode): array
    {
        $result = [];

        foreach ($arrayNode->items as $item) {
            if ($item instanceof \PhpParser\Node\Expr\ArrayItem) {
                $key = $this->extractArrayKey($item->key);
                $value = $this->extractNodeValue($item->value);
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function extractArrayKey(?\PhpParser\Node $key): string|int
    {
        if ($key === null) {
            return 0;
        }

        if ($key instanceof \PhpParser\Node\Scalar\String_) {
            return $key->value;
        }

        if ($key instanceof \PhpParser\Node\Scalar\LNumber) {
            return $key->value;
        }

        if ($key instanceof \PhpParser\Node\Identifier) {
            return $key->toString();
        }

        return 0;
    }

    private function analyzeModel(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($modelClass);
            $model = $reflection->newInstanceWithoutConstructor();

            $analysis = [
                'table' => $model->getTable() ?? Str::snake(Str::pluralStudly(class_basename($modelClass))),
                'fillable' => $model->getFillable(),
                'casts' => $model->getCasts(),
                'dates' => $model->getDates(),
                'hidden' => $model->getHidden(),
                'appends' => $model->getAppends(),
                'columns' => $this->analyzeDatabaseColumns($model),
            ];

            return $analysis;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Analyze database columns for the model
     */
    private function analyzeDatabaseColumns($model): array
    {
        $columns = [];

        try {
            if (method_exists($model, 'getConnection')) {
                $connection = $model->getConnection();

                if (method_exists($connection, 'getSchemaBuilder')) {
                    $schema = $connection->getSchemaBuilder();
                    $table = $model->getTable();

                    if ($schema->hasTable($table)) {
                        $columnList = $schema->getColumnListing($table);

                        foreach ($columnList as $column) {
                            $type = $schema->getColumnType($table, $column);
                            $columns[$column] = [
                                'type' => $type,
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore database connection errors
        }

        return $columns;
    }

    private function analyzeModelRelations(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $relations = [];

        try {
            $reflection = new ReflectionClass($modelClass);

            foreach ($reflection->getMethods() as $method) {
                if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $returnType = $method->getReturnType();
                if (! $returnType) {
                    continue;
                }

                $returnTypeName = $returnType->getName();

                if (is_subclass_of($returnTypeName, \Illuminate\Database\Eloquent\Relations\Relation::class)) {
                    $relations[] = [
                        'method' => $method->getName(),
                        'type' => class_basename($returnTypeName),
                    ];
                }
            }
        } catch (\Exception $e) {
            // Skip relations that can't be analyzed
        }

        return $relations;
    }
}
