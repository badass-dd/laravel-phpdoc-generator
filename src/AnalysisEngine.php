<?php

namespace Badass\LazyDocs;

use Doctrine\DBAL\Types\Type;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class AnalysisEngine
{
    private DocumentationGenerator $generator;

    private array $config;

    private array $modelCache = [];

    public function __construct(DocumentationGenerator $generator, array $config)
    {
        $this->generator = $generator;
        $this->config = $config;
    }

    public function analyzeController(string $controllerClass): array
    {
        $analysis = $this->generator->analyze($controllerClass)->getAnalysis();
        $enhancedAnalysis = [];

        // First pass: enhance each method individually
        foreach ($analysis as $methodName => $methodAnalysis) {
            // Add controller class to analysis for model inference
            $methodAnalysis['controller_class'] = $controllerClass;
            $enhancedAnalysis[$methodName] = $this->enhanceMethodAnalysis($methodAnalysis);
        }

        // Second pass: inherit dynamic_fields from called internal methods
        foreach ($enhancedAnalysis as $methodName => $methodAnalysis) {
            $enhancedAnalysis[$methodName] = $this->inheritDynamicFieldsFromCalledMethods(
                $methodAnalysis,
                $enhancedAnalysis
            );
        }

        return $enhancedAnalysis;
    }

    /**
     * Inherit dynamic_fields from methods called via $this->methodName()
     */
    private function inheritDynamicFieldsFromCalledMethods(array $analysis, array $allMethods): array
    {
        $calls = $analysis['body']['calls'] ?? [];

        foreach ($calls as $call) {
            // Check if this is a call to $this->methodName()
            if (isset($call['type']) && $call['type'] === 'instance' && isset($call['method'])) {
                $calledMethod = $call['method'];

                // If we have analysis for this method, get its dynamic_fields
                if (isset($allMethods[$calledMethod])) {
                    $calledMethodDynamicFields = $allMethods[$calledMethod]['body']['operations']['dynamic_fields'] ?? [];

                    if (! empty($calledMethodDynamicFields)) {
                        // Initialize if not set
                        if (! isset($analysis['body']['operations']['dynamic_fields'])) {
                            $analysis['body']['operations']['dynamic_fields'] = [];
                        }

                        // Add unique dynamic fields
                        foreach ($calledMethodDynamicFields as $field) {
                            $exists = false;
                            foreach ($analysis['body']['operations']['dynamic_fields'] as $existing) {
                                if ($existing['field'] === $field['field']) {
                                    $exists = true;
                                    break;
                                }
                            }
                            if (! $exists) {
                                $analysis['body']['operations']['dynamic_fields'][] = $field;
                            }
                        }

                        // Re-enhance responses with the new dynamic_fields
                        $analysis = $this->enhanceResponseExamples($analysis);
                    }
                }
            }
        }

        return $analysis;
    }

    private function enhanceMethodAnalysis(array $analysis): array
    {
        if (isset($analysis['body']['calls'])) {
            foreach ($analysis['body']['calls'] as $call) {
                if ($this->isModelCall($call)) {
                    $analysis = $this->analyzeModelUsage($analysis, $call);
                }
            }
        }

        // Promote body_params from body.operations to root level for ScribeGenerator
        if (isset($analysis['body']['operations']['body_params']) && ! empty($analysis['body']['operations']['body_params'])) {
            $analysis['body_params'] = array_merge(
                $analysis['body_params'] ?? [],
                $analysis['body']['operations']['body_params']
            );
        }

        // Promote database.transaction to body.operations.database.transaction
        if (isset($analysis['body']['operations']['database']['transaction'])) {
            // Already tracked correctly
        }

        // Check for responses from ResponseAnalyzer or body.operations.responses
        if (isset($analysis['responses']) || isset($analysis['body']['operations']['responses'])) {
            $analysis = $this->enhanceResponseExamples($analysis);
        }

        if (isset($analysis['parameters'])) {
            $analysis = $this->enhanceValidationExamples($analysis);
        }

        return $analysis;
    }

    private function isModelCall(array $call): bool
    {
        if ($call['type'] !== 'static') {
            return false;
        }

        $className = $call['class'] ?? '';

        if (! class_exists($className)) {
            return false;
        }

        return is_subclass_of($className, \Illuminate\Database\Eloquent\Model::class) ||
               $className === \Illuminate\Database\Eloquent\Model::class;
    }

    private function analyzeModelUsage(array $analysis, array $call): array
    {
        $modelClass = $call['class'];

        if (! isset($this->modelCache[$modelClass])) {
            $this->modelCache[$modelClass] = $this->analyzeModel($modelClass);
        }

        $analysis['models'][$modelClass] = $this->modelCache[$modelClass];

        $methodName = $call['method'];
        $operationType = $this->inferOperationType($methodName);

        if ($operationType) {
            $analysis['operation_type'] = $operationType;

            switch ($operationType) {
                case 'index':
                    $analysis['pagination'] = $this->detectPagination($call);
                    break;
                case 'store':
                    $analysis['creates_model'] = $modelClass;
                    break;
                case 'update':
                    $analysis['updates_model'] = $modelClass;
                    break;
                case 'destroy':
                    $analysis['deletes_model'] = $modelClass;
                    break;
            }
        }

        return $analysis;
    }

    private function analyzeModel(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            $model = $reflection->newInstanceWithoutConstructor();

            $analysis = [
                'table' => $model->getTable() ?? Str::snake(Str::pluralStudly(class_basename($modelClass))),
                'primary_key' => $model->getKeyName(),
                'fillable' => $model->getFillable(),
                'casts' => $model->getCasts(),
                'dates' => property_exists($model, 'dates') ? $model->getDates() : [],
                'hidden' => $model->getHidden(),
                'appends' => $model->getAppends(),
                'relations' => $this->analyzeModelRelations($reflection),
                'columns' => $this->analyzeDatabaseColumns($model),
                'traits' => $this->analyzeModelTraits($reflection),
            ];

            return $analysis;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function analyzeModelRelations(\ReflectionClass $reflection): array
    {
        $relations = [];

        foreach ($reflection->getMethods() as $method) {
            if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $returnType = $method->getReturnType();
            if (! $returnType) {
                continue;
            }

            $returnTypeName = $returnType->getName();

            if (is_subclass_of($returnTypeName, Relation::class) || $returnTypeName === Relation::class) {
                try {
                    $model = $reflection->newInstanceWithoutConstructor();
                    $relation = $method->invoke($model);

                    if ($relation instanceof Relation) {
                        $relations[$method->getName()] = [
                            'type' => get_class($relation),
                            'related' => get_class($relation->getRelated()),
                            'foreign_key' => $relation->getForeignKeyName(),
                            'local_key' => $relation->getLocalKeyName(),
                            'owner_key' => method_exists($relation, 'getOwnerKeyName') ? $relation->getOwnerKeyName() : null,
                        ];
                    }
                } catch (\Exception $e) {
                }
            }
        }

        return $relations;
    }

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

                        $detailedColumns = [];
                        foreach ($columnList as $column) {
                            // Use getColumnType which is available in Laravel 12
                            $type = $schema->getColumnType($table, $column);
                            $detailedColumns[$column] = [
                                'type' => $type,
                            ];
                        }

                        return $detailedColumns;
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail - columns will be empty and fillable will be used
        }

        return $columns;
    }

    private function analyzeModelTraits(\ReflectionClass $reflection): array
    {
        $traits = [];

        foreach ($reflection->getTraits() as $trait) {
            $traits[] = $trait->getName();
        }

        return $traits;
    }

    private function inferOperationType(string $methodName): ?string
    {
        $patterns = [
            'index' => ['/index|list|all|getAll/i', 'index'],
            'show' => ['/show|find|getById|details/i', 'show'],
            'store' => ['/store|create|save|insert/i', 'store'],
            'update' => ['/update|edit|modify|patch/i', 'update'],
            'destroy' => ['/destroy|delete|remove/i', 'destroy'],
        ];

        foreach ($patterns as $type => [$pattern, $operation]) {
            if (preg_match($pattern, $methodName)) {
                return $operation;
            }
        }

        return null;
    }

    private function detectPagination(array $call): array
    {
        $pagination = [
            'has_pagination' => false,
            'method' => null,
            'per_page' => null,
        ];

        $methodName = strtolower($call['method']);

        if (in_array($methodName, ['paginate', 'simplepaginate', 'cursorpaginate'])) {
            $pagination['has_pagination'] = true;
            $pagination['method'] = $methodName;

            if (isset($call['arguments'][1])) {
                $arg = $call['arguments'][1];
                if (is_array($arg) && isset($arg['value']) && is_numeric($arg['value'])) {
                    $pagination['per_page'] = (int) $arg['value'];
                }
            }
        }

        return $pagination;
    }

    private function enhanceResponseExamples(array $analysis): array
    {
        // Use responses from ResponseAnalyzer if available and not empty, otherwise fall back to body.operations.responses
        $rawResponses = ! empty($analysis['responses'])
            ? $analysis['responses']
            : ($analysis['body']['operations']['responses'] ?? []);

        if (empty($rawResponses)) {
            return $analysis;
        }

        $enhancedResponses = [];

        // Get eager-loaded relations from body operations
        $eagerRelations = $analysis['body']['operations']['eager_relations'] ?? [];

        // Get dynamic fields (properties added to models at runtime)
        $dynamicFields = $analysis['body']['operations']['dynamic_fields'] ?? [];

        // Determine operation type from model_operations
        $baseOperationType = 'single';
        if (isset($analysis['model_operations']) && is_array($analysis['model_operations'])) {
            foreach ($analysis['model_operations'] as $op) {
                if (isset($op['operation']) && $op['operation'] === 'index') {
                    $baseOperationType = 'collection';
                    break;
                }
            }
        }

        // If no model operations detected, infer from method name
        if ($baseOperationType === 'single' && isset($analysis['name'])) {
            $methodName = strtolower($analysis['name']);
            $collectionMethods = ['index', 'list', 'all', 'search', 'filter', 'getall', 'browse'];
            foreach ($collectionMethods as $collectionMethod) {
                if (str_contains($methodName, $collectionMethod)) {
                    $baseOperationType = 'collection';
                    break;
                }
            }
        }

        foreach ($rawResponses as $response) {
            if (is_array($response)) {
                // Default to 200 if status is null or not set (common for response()->json($data))
                $status = $response['status'] ?? 200;

                // Skip if status is still null after default
                if ($status === null) {
                    $status = 200;
                }

                $enhancedResponse = $response;
                $enhancedResponse['status'] = $status;

                // Pass eager relations to response for example generation
                if (! empty($eagerRelations)) {
                    $enhancedResponse['eager_relations'] = $eagerRelations;
                }

                // Pass dynamic fields to response for example generation
                if (! empty($dynamicFields)) {
                    $enhancedResponse['dynamic_fields'] = $dynamicFields;
                }

                // Determine operation type - 201 (created) is always single, 200 depends on model ops
                $operationType = 'single';
                if ($status === 200 && $baseOperationType === 'collection') {
                    $operationType = 'collection';
                }

                // Override type based on model operations analysis
                // Set operation_type for responses that don't have specific type info (success, http_response, unknown)
                $genericTypes = ['success', 'http_response', 'unknown', null];
                if (! isset($response['type']) || in_array($response['type'], $genericTypes, true)) {
                    $enhancedResponse['operation_type'] = $operationType;
                }

                // Get models - use detected models or infer from controller name
                $models = $analysis['models'] ?? [];
                if (empty($models)) {
                    $models = $this->inferModelsFromController($analysis);
                }

                // Generate example if models are available, but preserve original content
                if (! empty($models) && $status >= 200 && $status < 300) {
                    $enhancedResponse['example'] = $this->generateResponseExample($enhancedResponse, $models);
                }

                // Use a unique key to preserve all responses, even with same status code
                $key = $status.'_'.md5(json_encode($response['content'] ?? $response['message'] ?? ''));
                $enhancedResponses[$key] = $enhancedResponse;
            }
        }

        // Convert back to simple indexed array
        $analysis['responses'] = array_values($enhancedResponses);

        return $analysis;
    }

    private function generateResponseExample(array $response, array $models): array
    {
        $operationType = $response['operation_type'] ?? $response['type'] ?? 'single';
        $status = $response['status'] ?? 200;
        $content = $response['content'] ?? [];

        // 204 No Content - return empty array (will be rendered as empty or skipped)
        if ($status === 204) {
            return [];
        }

        if ($status < 200 || $status >= 300) {
            return $this->generateErrorExample($status);
        }

        // If the response has explicit structure with named keys (like 'message', 'data', 'sessione'),
        // preserve that structure and fill in the model data where variables are present
        if (is_array($content) && $this->hasExplicitResponseStructure($content)) {
            return $this->generateStructuredExample($content, $models, $response);
        }

        switch ($operationType) {
            case 'collection':
                return $this->generateCollectionExample($models, $response);
            case 'paginated':
                return $this->generatePaginatedExample($models, $response);
            case 'resource':
                return $this->generateApiResourceExample($models, $response);
            default:
                return $this->generateSingleExample($models, $response);
        }
    }

    /**
     * Check if the response content has an explicit structure with named keys
     */
    private function hasExplicitResponseStructure(array $content): bool
    {
        if (empty($content)) {
            return false;
        }

        // Check for common wrapper keys that indicate explicit structure
        $structureKeys = ['message', 'data', 'meta', 'status', 'error', 'errors', 'success'];

        foreach ($structureKeys as $key) {
            if (array_key_exists($key, $content)) {
                return true;
            }
        }

        // Also check if content has string keys (associative array) with variable placeholders
        foreach ($content as $key => $value) {
            if (is_string($key) && ! is_numeric($key)) {
                if (is_array($value) && isset($value['__variable__'])) {
                    return true;
                }
                // Match {$varName} or {$var['key']}
                if (is_string($value) && preg_match('/^\{\$\w+/', $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate example preserving the explicit response structure
     */
    private function generateStructuredExample(array $content, array $models, array $response): array
    {
        $result = [];

        foreach ($content as $key => $value) {
            if (is_array($value) && isset($value['__variable__'])) {
                // This is a variable placeholder - generate example based on key name
                $result[$key] = $this->generateExampleForKey($key, $value['__variable__'], $models, $response);
            } elseif (is_string($value) && preg_match('/^\{\$(\w+)/', $value, $matches)) {
                // String variable placeholder like {$varName} or {$var['key']}
                $result[$key] = $this->generateExampleForKey($key, $matches[1], $models, $response);
            } elseif (is_array($value)) {
                // Nested array - recurse
                $result[$key] = $this->generateStructuredExample($value, $models, $response);
            } else {
                // Keep literal value
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Generate example value for a specific key based on context
     */
    private function generateExampleForKey(string $key, string $varName, array $models, array $response): mixed
    {
        $keyLower = strtolower($key);
        $varLower = strtolower($varName);

        // If the key/variable suggests a model, generate model instance
        $modelKeywords = ['sessione', 'session', 'patient', 'user', 'item', 'resource', 'record', 'data'];

        foreach ($modelKeywords as $keyword) {
            if (str_contains($keyLower, $keyword) || str_contains($varLower, $keyword)) {
                // Try to find matching model
                foreach ($models as $modelClass => $modelInfo) {
                    $modelName = strtolower(class_basename($modelClass));
                    if (str_contains($keyLower, $modelName) || str_contains($varLower, $modelName)) {
                        $eagerRelations = $response['eager_relations'] ?? [];
                        $instance = $this->generateModelInstance($modelClass, $modelInfo, 1);

                        foreach ($eagerRelations as $relation) {
                            $instance[$relation] = [$this->generateRelatedInstance($relation, $modelClass, $modelInfo)];
                        }

                        return $instance;
                    }
                }

                // No matching model found, use first available model
                if (! empty($models)) {
                    $modelClass = array_key_first($models);
                    $modelInfo = $models[$modelClass];
                    $eagerRelations = $response['eager_relations'] ?? [];
                    $instance = $this->generateModelInstance($modelClass, $modelInfo, 1);

                    foreach ($eagerRelations as $relation) {
                        $instance[$relation] = [$this->generateRelatedInstance($relation, $modelClass, $modelInfo)];
                    }

                    return $instance;
                }

                return ['id' => 1];
            }
        }

        // For job/batch related keys
        if (str_contains($keyLower, 'job') || str_contains($keyLower, 'batch')) {
            if (str_contains($keyLower, 'error')) {
                return 'Error message';
            }
            if (str_contains($keyLower, 'id')) {
                return 'batch-job-'.rand(1000, 9999);
            }

            return 'batch-job-'.rand(1000, 9999);
        }

        // For error-related keys
        if (str_contains($keyLower, 'error')) {
            return null;
        }

        // Default placeholder
        return '...';
    }

    private function generateCollectionExample(array $models, array $response): array
    {
        // If no models, return simple array
        if (empty($models)) {
            return [['id' => 1]];
        }

        // Get the first model
        $modelClass = array_key_first($models);
        $modelInfo = $models[$modelClass];

        // Get eager relations from response
        $eagerRelations = $response['eager_relations'] ?? [];

        // Get dynamic fields (properties added at runtime)
        $dynamicFields = $response['dynamic_fields'] ?? [];

        $items = [];
        $count = 1; // Just one example item for collections

        for ($i = 1; $i <= $count; $i++) {
            $item = $this->generateModelInstance($modelClass, $modelInfo, $i);

            // Add eager-loaded relations (handling nested relations like 'questionnaires.answers')
            $item = $this->addEagerLoadedRelations($item, $eagerRelations, $modelClass, $modelInfo);

            // Add dynamic fields
            foreach ($dynamicFields as $dynamicField) {
                $fieldName = $dynamicField['field'];
                $fieldType = $dynamicField['value_type'] ?? 'string';
                $item[$fieldName] = $this->generateDynamicFieldValue($fieldName, $fieldType);
            }

            $items[] = $item;
        }

        // Return the array directly (not wrapped in a key)
        // This matches the common pattern: return response()->json($collection);
        return $items;
    }

    private function generateSingleExample(array $models, array $response): array
    {
        // If no models detected, return a simple object with just id
        if (empty($models)) {
            return ['id' => 1];
        }

        // Generate instance from the first model
        $modelClass = array_key_first($models);
        $modelInfo = $models[$modelClass];
        $modelInstance = $this->generateModelInstance($modelClass, $modelInfo, 1);

        // Get eager relations from response or body operations
        $eagerRelations = $response['eager_relations'] ?? [];

        // Add eager-loaded relations (handling nested relations like 'questionnaires.answers')
        $modelInstance = $this->addEagerLoadedRelations($modelInstance, $eagerRelations, $modelClass, $modelInfo);

        // Get dynamic fields (properties added at runtime)
        $dynamicFields = $response['dynamic_fields'] ?? [];

        foreach ($dynamicFields as $dynamicField) {
            $fieldName = $dynamicField['field'];
            $fieldType = $dynamicField['value_type'] ?? 'string';
            $modelInstance[$fieldName] = $this->generateDynamicFieldValue($fieldName, $fieldType);
        }

        // Return the model instance directly (not wrapped in a key)
        // This matches the common pattern: return response()->json($model);
        return $modelInstance;
    }

    private function generateModelInstance(string $modelClass, array $modelInfo, int $id): array
    {
        $instance = ['id' => $id];
        $faker = \Faker\Factory::create();

        // First try columns (from database schema)
        if (isset($modelInfo['columns']) && is_array($modelInfo['columns']) && ! empty($modelInfo['columns'])) {
            foreach ($modelInfo['columns'] as $columnName => $columnInfo) {
                if (in_array($columnName, ['password', 'remember_token', 'deleted_at'])) {
                    continue;
                }

                if (is_int($columnName) && is_string($columnInfo)) {
                    $columnName = $columnInfo;
                    $columnInfo = [];
                }

                $instance[$columnName] = $this->generateFieldValue($columnName, is_array($columnInfo) ? $columnInfo : [], $faker, $id);
            }
        }
        // Then try fillable (from model definition)
        elseif (isset($modelInfo['fillable']) && is_array($modelInfo['fillable']) && ! empty($modelInfo['fillable'])) {
            foreach ($modelInfo['fillable'] as $field) {
                // Determine type from casts if available
                $type = 'string';
                if (isset($modelInfo['casts'][$field])) {
                    $castType = $modelInfo['casts'][$field];
                    if (in_array($castType, ['integer', 'int'])) {
                        $type = 'integer';
                    } elseif (in_array($castType, ['float', 'double', 'decimal'])) {
                        $type = 'float';
                    } elseif (in_array($castType, ['boolean', 'bool'])) {
                        $type = 'boolean';
                    } elseif (in_array($castType, ['array', 'json', 'object', 'collection'])) {
                        $type = 'json';
                    } elseif (in_array($castType, ['datetime', 'date', 'timestamp'])) {
                        $type = 'datetime';
                    }
                }

                $instance[$field] = $this->generateFieldValue($field, ['type' => $type], $faker, $id);
            }
        }
        // Fallback to common fields based on model name
        else {
            $instance = $this->generateCommonFields($modelClass, $faker, $id);
        }

        if (! isset($instance['created_at'])) {
            $timestamp = $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');
            $instance['created_at'] = $timestamp;
            $instance['updated_at'] = $timestamp;
        }

        return $instance;
    }

    private function generateFieldValue(string $fieldName, array $columnInfo, $faker, int $id): mixed
    {
        $fieldLower = strtolower($fieldName);
        $type = $columnInfo['type'] ?? 'string';

        // Handle _id fields first - they should always be integers
        if (str_ends_with($fieldName, '_id')) {
            return $faker->numberBetween(1, 100);
        }

        // Handle value/score fields that are likely integers
        if (str_ends_with($fieldName, '_value') || str_ends_with($fieldName, '_score')) {
            return $faker->numberBetween(0, 100);
        }

        // Handle timestamp/datetime fields with specific suffixes
        if (preg_match('/_at$|_date$|_time$|_update$/', $fieldName)) {
            return $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');
        }

        // Handle fields that are likely JSON (annotations, settings, options, config, metadata, etc.)
        // Use word boundaries to avoid matching fields like 'data_update'
        if (preg_match('/\b(annotations|settings|options|config|metadata|json)\b/i', $fieldName) ||
            ($fieldName === 'data')) {
            return ['key' => 'value'];
        }

        // Check database type FIRST to respect the actual column type
        // This prevents patterns from overriding integer columns with string values
        $integerTypes = ['int', 'integer', 'bigint', 'smallint', 'tinyint'];
        if (in_array($type, $integerTypes)) {
            // For integer fields, return appropriate integer values
            if (str_contains($fieldLower, 'type') || str_contains($fieldLower, 'status') || str_contains($fieldLower, 'state')) {
                return $faker->numberBetween(1, 10);
            }

            return $faker->numberBetween(1, 100);
        }

        $patterns = [
            '/email/' => fn () => $faker->email(),
            '/name/' => fn () => $faker->name(),
            '/first_name/' => fn () => $faker->firstName(),
            '/last_name/' => fn () => $faker->lastName(),
            '/phone|mobile|tel/' => fn () => $faker->phoneNumber(),
            '/address|street/' => fn () => $faker->address(),
            '/city/' => fn () => $faker->city(),
            '/country/' => fn () => $faker->country(),
            '/zip|postcode/' => fn () => $faker->postcode(),
            '/description|bio|about/' => fn () => $faker->paragraph(),
            '/title|subject/' => fn () => $faker->sentence(),
            '/content|body|message/' => fn () => $faker->paragraphs(2, true),
            '/price|amount|cost|total/' => fn () => $faker->randomFloat(2, 1, 1000),
            '/quantity|count|number/' => fn () => $faker->numberBetween(1, 100),
            '/status/' => fn () => $faker->randomElement(['active', 'inactive', 'pending']),
            '/type/' => fn () => $faker->randomElement(['standard', 'premium', 'vip']),
            '/url|website|link/' => fn () => $faker->url(),
            '/image|photo|avatar/' => fn () => $faker->imageUrl(),
            '/note/' => fn () => $faker->sentence(),
        ];

        foreach ($patterns as $pattern => $generator) {
            if (preg_match($pattern, $fieldLower)) {
                return $generator();
            }
        }

        switch ($type) {
            case 'int':
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return str_ends_with($fieldName, '_id') ? $faker->numberBetween(1, 100) : $id;
            case 'decimal':
            case 'float':
            case 'double':
                return $faker->randomFloat(2, 0, 1000);
            case 'boolean':
            case 'bool':
                return $faker->boolean();
            case 'datetime':
            case 'timestamp':
                return $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');
            case 'date':
                return $faker->date();
            case 'time':
                return $faker->time();
            case 'json':
            case 'array':
            case 'object':
                return ['key' => 'value'];
            case 'text':
            case 'longtext':
            case 'mediumtext':
                return $faker->text(200);
            default:
                return $faker->word();
        }
    }

    /**
     * Generate a value for a dynamically added field based on its name and type
     */
    private function generateDynamicFieldValue(string $fieldName, string $type): mixed
    {
        $faker = \Faker\Factory::create();
        $fieldLower = strtolower($fieldName);

        // Handle common dynamic field patterns by name
        if (str_contains($fieldLower, 'status')) {
            return $faker->randomElement(['Created', 'Started', 'Completed', 'Pending']);
        }

        if (str_contains($fieldLower, 'count') || str_contains($fieldLower, 'number')) {
            return $faker->numberBetween(1, 100);
        }

        if (str_contains($fieldLower, 'flag') || str_contains($fieldLower, 'is_') || str_contains($fieldLower, 'has_')) {
            return $faker->boolean();
        }

        // Generate based on type
        return match ($type) {
            'string' => $faker->word(),
            'integer', 'int' => $faker->numberBetween(1, 100),
            'float', 'double' => $faker->randomFloat(2, 0, 100),
            'boolean', 'bool' => $faker->boolean(),
            'array' => ['key' => 'value'],
            default => $faker->word(),
        };
    }

    /**
     * Add eager-loaded relations to an item, handling nested relations like 'questionnaires.answers'
     */
    private function addEagerLoadedRelations(array $item, array $eagerRelations, string $modelClass, array $modelInfo): array
    {
        // Group relations by their top-level key
        $relationTree = [];
        foreach ($eagerRelations as $relation) {
            $parts = explode('.', $relation);
            $topLevel = $parts[0];

            if (! isset($relationTree[$topLevel])) {
                $relationTree[$topLevel] = [];
            }

            // If there are nested relations, store them
            if (count($parts) > 1) {
                $nestedRelation = implode('.', array_slice($parts, 1));
                $relationTree[$topLevel][] = $nestedRelation;
            }
        }

        // Process each top-level relation
        foreach ($relationTree as $relation => $nestedRelations) {
            $relatedInstance = $this->generateRelatedInstance($relation, $modelClass, $modelInfo);

            // Get the related model class to process nested relations
            $relatedModelClass = $this->getRelatedModelClass($modelClass, $relation);

            // If there are nested relations, add them to the related instance
            if (! empty($nestedRelations) && $relatedModelClass) {
                $relatedModelInfo = $this->analyzeModel($relatedModelClass);
                $relatedInstance = $this->addEagerLoadedRelations(
                    $relatedInstance,
                    $nestedRelations,
                    $relatedModelClass,
                    $relatedModelInfo
                );
            }

            $item[$relation] = [$relatedInstance];
        }

        return $item;
    }

    /**
     * Get the related model class from a relationship name
     */
    private function getRelatedModelClass(string $parentModelClass, string $relation): ?string
    {
        if (! class_exists($parentModelClass)) {
            return null;
        }

        try {
            $parentInstance = new $parentModelClass;
            if (method_exists($parentInstance, $relation)) {
                $relationObj = $parentInstance->$relation();
                if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    return get_class($relationObj->getRelated());
                }
            }
        } catch (\Exception $e) {
            // Ignore exceptions during reflection
        }

        return null;
    }

    private function generateRelatedInstance(string $relation, ?string $parentModelClass = null, ?array $parentModelInfo = null): array
    {
        $faker = \Faker\Factory::create();
        $relationLower = strtolower($relation);

        // Try to get the related model class from the parent model's relationships
        $relatedModelClass = null;
        if ($parentModelClass && class_exists($parentModelClass)) {
            try {
                $parentInstance = new $parentModelClass;
                if (method_exists($parentInstance, $relation)) {
                    $relationObj = $parentInstance->$relation();
                    if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relatedModelClass = get_class($relationObj->getRelated());
                    }
                }
            } catch (\Exception $e) {
                // Ignore exceptions during reflection
            }
        }

        // If we found the related model, generate an example from its schema
        if ($relatedModelClass && class_exists($relatedModelClass)) {
            $relatedModelInfo = $this->analyzeModel($relatedModelClass);

            return $this->generateModelInstance($relatedModelClass, $relatedModelInfo, 1);
        }

        // Fallback to generic examples based on relation name
        if (str_contains($relationLower, 'user')) {
            return [
                'id' => $faker->numberBetween(1, 100),
                'name' => $faker->name(),
                'email' => $faker->email(),
            ];
        }

        if (str_contains($relationLower, 'answer')) {
            return [
                'id' => $faker->numberBetween(1, 100),
                'questionnaire_id' => $faker->numberBetween(1, 100),
                'index' => $faker->numberBetween(1, 20),
                'question' => $faker->word(),
                'answer_value' => $faker->word(),
            ];
        }

        if (str_contains($relationLower, 'product')) {
            return [
                'id' => $faker->numberBetween(1, 100),
                'name' => $faker->word(),
                'price' => $faker->randomFloat(2, 10, 1000),
            ];
        }

        if (str_contains($relationLower, 'order')) {
            return [
                'id' => $faker->numberBetween(1, 100),
                'total' => $faker->randomFloat(2, 50, 5000),
                'status' => $faker->randomElement(['pending', 'processing', 'completed']),
            ];
        }

        return [
            'id' => $faker->numberBetween(1, 100),
            'name' => $faker->word(),
        ];
    }

    private function generatePaginatedExample(array $models, array $response): array
    {
        $data = $this->generateCollectionExample($models, $response);
        $modelClass = array_key_first($models);
        $key = $this->getCollectionKey($modelClass);
        $items = $data[$key] ?? [];

        return [
            'data' => $items,
            'links' => [
                'first' => 'http://example.com/api/'.strtolower($key).'?page=1',
                'last' => 'http://example.com/api/'.strtolower($key).'?page=5',
                'prev' => null,
                'next' => 'http://example.com/api/'.strtolower($key).'?page=2',
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 5,
                'links' => [
                    ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                    ['url' => 'http://example.com/api/'.strtolower($key).'?page=1', 'label' => '1', 'active' => true],
                    ['url' => 'http://example.com/api/'.strtolower($key).'?page=2', 'label' => '2', 'active' => false],
                ],
                'path' => 'http://example.com/api/'.strtolower($key),
                'per_page' => 15,
                'to' => 15,
                'total' => 75,
            ],
        ];
    }

    private function generateApiResourceExample(array $models, array $response): array
    {
        $data = $this->generateSingleExample($models, $response);

        return [
            'data' => $data,
            'links' => [
                'self' => 'http://example.com/api/'.strtolower(array_key_first($models)).'/1',
            ],
        ];
    }

    private function generateErrorExample(int $status): array
    {
        return [
            'success' => false,
            'message' => $this->getErrorMessage($status),
            'errors' => $status === 422 ? ['field' => ['Error message']] : null,
        ];
    }

    private function enhanceValidationExamples(array $analysis): array
    {
        foreach ($analysis['parameters'] as &$param) {
            if (isset($param['validation_rules'])) {
                $param['examples'] = $this->generateValidationExamples(
                    $param['name'],
                    $param['validation_rules']
                );
            }
        }

        return $analysis;
    }

    private function generateValidationExamples(string $fieldName, array $rules): array
    {
        $faker = \Faker\Factory::create();
        $examples = [];
        $fieldLower = strtolower($fieldName);

        $patterns = [
            '/email/' => fn () => $faker->email(),
            '/name/' => fn () => $faker->name(),
            '/phone|mobile|tel/' => fn () => $faker->phoneNumber(),
            '/address|street/' => fn () => $faker->address(),
            '/city/' => fn () => $faker->city(),
            '/state|province/' => fn () => $faker->state(),
            '/zip|postcode/' => fn () => $faker->postcode(),
            '/country/' => fn () => $faker->country(),
            '/date/' => fn () => $faker->date(),
            '/price|amount|cost/' => fn () => $faker->randomFloat(2, 1, 1000),
            '/quantity|count/' => fn () => $faker->numberBetween(1, 100),
        ];

        foreach ($patterns as $pattern => $generator) {
            if (preg_match($pattern, $fieldLower)) {
                $examples[] = $generator();
                break;
            }
        }

        if (empty($examples)) {
            foreach ($rules as $rule) {
                if (str_contains($rule, 'integer')) {
                    $examples[] = $faker->numberBetween(1, 100);
                    break;
                } elseif (str_contains($rule, 'numeric')) {
                    $examples[] = $faker->randomFloat(2, 1, 100);
                    break;
                } elseif (str_contains($rule, 'boolean')) {
                    $examples[] = $faker->boolean();
                    break;
                }
            }
        }

        if (empty($examples)) {
            $examples[] = $faker->word();
        }

        return $examples;
    }

    private function getCollectionKey(string $modelClass): string
    {
        $basename = class_basename($modelClass);

        return \Illuminate\Support\Str::plural(\Illuminate\Support\Str::camel($basename));
    }

    private function getSingleKey(string $modelClass): string
    {
        $basename = class_basename($modelClass);

        return \Illuminate\Support\Str::camel($basename);
    }

    private function getSuccessMessage(int $status): string
    {
        $messages = [
            200 => 'Request successful',
            201 => 'Resource created successfully',
            202 => 'Request accepted',
            204 => 'Resource deleted successfully',
        ];

        return $messages[$status] ?? 'Operation completed successfully';
    }

    private function getErrorMessage(int $status): string
    {
        $messages = [
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Resource not found',
            422 => 'Validation failed',
            429 => 'Too many requests',
            500 => 'Internal server error',
        ];

        return $messages[$status] ?? 'An error occurred';
    }

    private function generateCommonFields(string $modelClass, $faker, int $id): array
    {
        $modelName = class_basename($modelClass);
        $fields = ['id' => $id];

        if (str_contains(strtolower($modelName), 'user')) {
            $fields['name'] = $faker->name();
            $fields['email'] = $faker->email();
        } elseif (str_contains(strtolower($modelName), 'product')) {
            $fields['name'] = $faker->word();
            $fields['price'] = $faker->randomFloat(2, 10, 1000);
        } elseif (str_contains(strtolower($modelName), 'order')) {
            $fields['total'] = $faker->randomFloat(2, 50, 5000);
            $fields['status'] = $faker->randomElement(['pending', 'processing', 'completed']);
        } else {
            $fields['name'] = $faker->word();
            $fields['description'] = $faker->sentence();
        }

        $timestamp = $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');
        $fields['created_at'] = $timestamp;
        $fields['updated_at'] = $timestamp;

        return $fields;
    }

    private function generateGenericData(array $models): array
    {
        if (empty($models)) {
            return ['id' => 1];
        }

        $modelClass = array_key_first($models);
        $modelInfo = $models[$modelClass];

        return $this->generateModelInstance($modelClass, $modelInfo, 1);
    }

    /**
     * Infer the model class from the controller name.
     * E.g., SessioneDisartriaController → App\Models\SessioneDisartria
     */
    private function inferModelsFromController(array $analysis): array
    {
        // Try controller_class first (from enhanceMethodAnalysis), then class (legacy)
        $controllerClass = $analysis['controller_class'] ?? $analysis['class'] ?? null;

        if (! $controllerClass) {
            return [];
        }

        // Extract the short controller name
        $shortName = class_basename($controllerClass);

        // Remove 'Controller' suffix to get model name
        $modelName = preg_replace('/Controller$/', '', $shortName);

        if (empty($modelName) || $modelName === $shortName) {
            return [];
        }

        // Try common model namespaces
        $possibleNamespaces = [
            'App\\Models\\',
            'App\\',
        ];

        foreach ($possibleNamespaces as $namespace) {
            $modelClass = $namespace.$modelName;

            if (class_exists($modelClass)) {
                // Use existing analyzeModel method to get full model info
                return [$modelClass => $this->analyzeModel($modelClass)];
            }
        }

        return [];
    }
}
