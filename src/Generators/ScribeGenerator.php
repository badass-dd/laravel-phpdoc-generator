<?php

namespace Badass\LazyDocs\Generators;

use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

class ScribeGenerator
{
    private array $analysis;

    private string $controllerClass;

    private string $methodName;

    private array $config;

    private ?string $existingDoc;

    private \Faker\Generator $faker;

    private ?string $existingGroup = null;

    /**
     * Metadata map of already documented items from PHPDoc and Attributes
     */
    private array $documentedMetadata = [];

    /**
     * PHP 8 Attributes found on the method
     */
    private array $methodAttributes = [];

    public function __construct(
        array $analysis,
        string $controllerClass,
        string $methodName,
        array $config,
        ?string $existingDoc = null
    ) {
        $this->analysis = $analysis;
        $this->controllerClass = $controllerClass;
        $this->methodName = $methodName;
        $this->config = $config;
        $this->existingDoc = $existingDoc;
        $this->faker = \Faker\Factory::create();

        // Build metadata map from existing PHPDoc and Attributes
        $this->buildMetadataMap();
    }

    /**
     * Build a metadata map of already documented items from both PHPDoc and PHP 8 Attributes.
     * This enables the "Smart Completion" strategy where user-defined documentation takes priority.
     */
    private function buildMetadataMap(): void
    {
        $this->documentedMetadata = [
            'group' => null,
            'authenticated' => false,
            'body_params' => [],
            'query_params' => [],
            'url_params' => [],
            'responses' => [],
            'headers' => [],
            'description' => null,
            'title' => null,
        ];

        // Extract from existing PHPDoc
        if ($this->existingDoc) {
            $this->extractFromPhpDoc($this->existingDoc);
        }

        // Extract from PHP 8 Attributes (takes priority over PHPDoc)
        $this->extractFromAttributes();
    }

    /**
     * Extract documented metadata from existing PHPDoc block
     */
    private function extractFromPhpDoc(string $doc): void
    {
        // Extract title and description
        // Title is the first non-empty, non-tag line BEFORE any @response tag with content
        // In Scribe, title appears at the start or after @group (but before content tags)
        $lines = explode("\n", $doc);
        $nonTagLines = [];
        $inResponseBlock = false;
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $trimmed = preg_replace('/^\s*\*\s?/', '', $trimmed);
            
            // Skip opening/closing
            if (str_starts_with($trimmed, '/**') || str_starts_with($trimmed, '*/')) {
                continue;
            }
            
            // Check if this is a @response tag - marks the start of a response block
            if (str_starts_with($trimmed, '@response')) {
                $inResponseBlock = true;
                continue;
            }
            
            // Any other tag resets the response block state
            if (str_starts_with($trimmed, '@')) {
                $inResponseBlock = false;
                continue;
            }
            
            // Skip empty lines
            if ($trimmed === '') {
                // Empty line might end a response block
                $inResponseBlock = false;
                continue;
            }
            
            // If we're in a response block, skip this line (it's JSON content)
            if ($inResponseBlock) {
                continue;
            }
            
            // Skip lines that look like JSON (response content)
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[') || str_starts_with($trimmed, '"')) {
                continue;
            }
            
            // This is a non-tag, non-empty, non-JSON line - it's title or description text
            $nonTagLines[] = $trimmed;
        }
        
        // First non-tag line is title, rest is description
        if (! empty($nonTagLines)) {
            $this->documentedMetadata['title'] = array_shift($nonTagLines);
            if (! empty($nonTagLines)) {
                $this->documentedMetadata['description'] = implode(' ', $nonTagLines);
            }
        }

        // Extract @group
        if (preg_match('/@group\s+(.+)$/m', $doc, $matches)) {
            $this->documentedMetadata['group'] = trim($matches[1]);
        }

        // Extract @authenticated
        if (preg_match('/@authenticated/', $doc)) {
            $this->documentedMetadata['authenticated'] = true;
        }

        // Extract @bodyParam tags with full content
        // Format: @bodyParam name type required|optional description Example: value
        if (preg_match_all('/@bodyParam\s+(\S+)\s+(\S+)\s+(required|optional)?\s*([^E]*?)(?:Example:\s*(.+))?$/m', $doc, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->documentedMetadata['body_params'][$match[1]] = [
                    'name' => $match[1],
                    'type' => $match[2],
                    'required' => ($match[3] ?? 'required') === 'required',
                    'description' => trim($match[4] ?? ''),
                    'example' => isset($match[5]) ? trim($match[5]) : null,
                    'source' => 'phpdoc',
                    'raw' => trim($match[0]),
                ];
            }
        }

        // Extract @queryParam tags with full content
        // Format: @queryParam name type required|optional description Example: value
        if (preg_match_all('/@queryParam\s+(\S+)\s+(\S+)\s+(required|optional)?\s*([^E]*?)(?:Example:\s*(.+))?$/m', $doc, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->documentedMetadata['query_params'][$match[1]] = [
                    'name' => $match[1],
                    'type' => $match[2],
                    'required' => ($match[3] ?? 'optional') === 'required',
                    'description' => trim($match[4] ?? ''),
                    'example' => isset($match[5]) ? trim($match[5]) : null,
                    'source' => 'phpdoc',
                    'raw' => trim($match[0]),
                ];
            }
        }

        // Extract @urlParam tags with full content
        // Format: @urlParam name type required|optional description Example: value
        if (preg_match_all('/@urlParam\s+(\S+)\s+(\S+)\s+(required|optional)?\s*([^E]*?)(?:Example:\s*(\S+))?$/m', $doc, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->documentedMetadata['url_params'][$match[1]] = [
                    'name' => $match[1],
                    'type' => $match[2],
                    'required' => ($match[3] ?? 'required') === 'required',
                    'description' => trim($match[4] ?? ''),
                    'example' => $match[5] ?? null,
                    'source' => 'phpdoc',
                    'raw' => trim($match[0]),
                ];
            }
        }

        // Extract @response tags with full JSON content using line-by-line parsing
        $this->extractResponseTags($doc);

        // Extract @header tags
        if (preg_match_all('/@header\s+(\S+)/', $doc, $matches)) {
            foreach ($matches[1] as $header) {
                $this->documentedMetadata['headers'][$header] = [
                    'name' => $header,
                    'source' => 'phpdoc',
                ];
            }
        }
    }

    /**
     * Extract @response tags with their full JSON content
     * Handles multi-line JSON properly
     */
    private function extractResponseTags(string $doc): void
    {
        $lines = explode("\n", $doc);
        $currentResponse = null;
        $jsonLines = [];
        $braceCount = 0;
        $bracketCount = 0;
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $trimmed = preg_replace('/^\s*\*\s?/', '', $trimmed);
            
            // Check for new @response tag
            if (preg_match('/^@response\s+(\d+)\s*(\w+)?(.*)$/', $trimmed, $match)) {
                // Save previous response if exists
                if ($currentResponse !== null) {
                    $this->saveExtractedResponse($currentResponse, $jsonLines);
                }
                
                // Start new response
                $status = (int) $match[1];
                $type = ! empty($match[2]) ? trim($match[2]) : null;
                $rest = isset($match[3]) ? trim($match[3]) : '';
                
                $currentResponse = [
                    'status' => $status,
                    'type' => $type,
                ];
                $jsonLines = [];
                $braceCount = 0;
                $bracketCount = 0;
                
                // Check if JSON starts on the same line
                if ($rest !== '') {
                    $braceCount += substr_count($rest, '{') - substr_count($rest, '}');
                    $bracketCount += substr_count($rest, '[') - substr_count($rest, ']');
                    $jsonLines[] = $rest;
                }
                continue;
            }
            
            // Check for other tags (end of current response)
            if (str_starts_with($trimmed, '@') && $currentResponse !== null) {
                $this->saveExtractedResponse($currentResponse, $jsonLines);
                $currentResponse = null;
                $jsonLines = [];
                continue;
            }
            
            // Skip doc boundaries
            if (str_starts_with($trimmed, '/**') || str_starts_with($trimmed, '*/') || $trimmed === '/') {
                continue;
            }
            
            // Accumulate JSON content for current response
            if ($currentResponse !== null && $trimmed !== '') {
                $braceCount += substr_count($trimmed, '{') - substr_count($trimmed, '}');
                $bracketCount += substr_count($trimmed, '[') - substr_count($trimmed, ']');
                $jsonLines[] = $trimmed;
            }
        }
        
        // Save last response
        if ($currentResponse !== null) {
            $this->saveExtractedResponse($currentResponse, $jsonLines);
        }
    }
    
    /**
     * Save an extracted response to the metadata map
     */
    private function saveExtractedResponse(array $response, array $jsonLines): void
    {
        $status = $response['status'];
        $content = ! empty($jsonLines) ? implode("\n", $jsonLines) : null;
        
        $this->documentedMetadata['responses'][$status] = [
            'status' => $status,
            'type' => $response['type'],
            'content' => $content,
            'source' => 'phpdoc',
        ];
    }

    /**
     * Extract documented metadata from PHP 8 Attributes
     * Attributes take priority over PHPDoc tags
     */
    private function extractFromAttributes(): void
    {
        if (! class_exists($this->controllerClass)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($this->controllerClass);

            if (! $reflection->hasMethod($this->methodName)) {
                return;
            }

            $method = $reflection->getMethod($this->methodName);
            $attributes = $method->getAttributes();

            foreach ($attributes as $attribute) {
                $this->processAttribute($attribute);
            }

            $this->methodAttributes = $attributes;
        } catch (\Exception $e) {
            // Silently fail if reflection fails
        }
    }

    /**
     * Process a single PHP 8 Attribute and update the metadata map
     */
    private function processAttribute(ReflectionAttribute $attribute): void
    {
        $attributeName = $attribute->getName();
        $shortName = class_basename($attributeName);

        try {
            $args = $attribute->getArguments();
        } catch (\Exception $e) {
            $args = [];
        }

        // Handle common Scribe attributes
        switch ($shortName) {
            case 'Group':
                $this->documentedMetadata['group'] = $args[0] ?? $args['name'] ?? null;
                break;

            case 'Authenticated':
                $this->documentedMetadata['authenticated'] = true;
                break;

            case 'Unauthenticated':
                $this->documentedMetadata['authenticated'] = false;
                break;

            case 'BodyParam':
                $name = $args['name'] ?? $args[0] ?? null;
                if ($name) {
                    $this->documentedMetadata['body_params'][$name] = [
                        'name' => $name,
                        'type' => $args['type'] ?? $args[1] ?? 'string',
                        'source' => 'attribute',
                    ];
                }
                break;

            case 'QueryParam':
                $name = $args['name'] ?? $args[0] ?? null;
                if ($name) {
                    $this->documentedMetadata['query_params'][$name] = [
                        'name' => $name,
                        'type' => $args['type'] ?? $args[1] ?? 'string',
                        'source' => 'attribute',
                    ];
                }
                break;

            case 'UrlParam':
                $name = $args['name'] ?? $args[0] ?? null;
                if ($name) {
                    $this->documentedMetadata['url_params'][$name] = [
                        'name' => $name,
                        'type' => $args['type'] ?? $args[1] ?? 'string',
                        'source' => 'attribute',
                    ];
                }
                break;

            case 'Response':
            case 'ResponseFromFile':
            case 'ResponseFromApiResource':
                $status = $args['status'] ?? $args[0] ?? 200;
                $this->documentedMetadata['responses'][(int) $status] = [
                    'status' => (int) $status,
                    'source' => 'attribute',
                    'attribute' => $shortName,
                ];
                break;

            case 'Header':
                $name = $args['name'] ?? $args[0] ?? null;
                if ($name) {
                    $this->documentedMetadata['headers'][$name] = [
                        'name' => $name,
                        'source' => 'attribute',
                    ];
                }
                break;
        }
    }

    /**
     * Check if a body param is already documented (via PHPDoc or Attribute)
     */
    private function isBodyParamDocumented(string $paramName): bool
    {
        return isset($this->documentedMetadata['body_params'][$paramName]);
    }

    /**
     * Check if a query param is already documented (via PHPDoc or Attribute)
     */
    private function isQueryParamDocumented(string $paramName): bool
    {
        return isset($this->documentedMetadata['query_params'][$paramName]);
    }

    /**
     * Check if a URL param is already documented (via PHPDoc or Attribute)
     */
    private function isUrlParamDocumented(string $paramName): bool
    {
        return isset($this->documentedMetadata['url_params'][$paramName]);
    }

    /**
     * Check if a response status is already documented (via PHPDoc or Attribute)
     */
    private function isResponseDocumented(int $status): bool
    {
        return isset($this->documentedMetadata['responses'][$status]);
    }

    /**
     * Check if group is already documented
     */
    private function isGroupDocumented(): bool
    {
        return $this->documentedMetadata['group'] !== null;
    }

    /**
     * Check if authentication is already documented
     */
    private function isAuthenticationDocumented(): bool
    {
        return $this->documentedMetadata['authenticated'] !== false 
            || ! empty($this->documentedMetadata['headers']['Authorization']);
    }

    public function generate(): string
    {
        // Only preserve existing doc if NOT in overwrite/force mode
        $shouldPreserve = ($this->config['output']['merge_strategy'] ?? 'smart') !== 'overwrite';

        if (! empty($this->existingDoc)) {
            // In smart mode, we ALWAYS generate new docs but respect existing metadata
            // The metadata map has already been built in constructor from existing doc
            // This allows us to add missing tags while keeping existing ones
            
            $this->existingGroup = $this->extractExistingGroup($this->existingDoc);
        }

        $lines = ['/**'];

        $this->addTitleAndDescription($lines);
        $this->addImplementationNotes($lines);
        $this->addBodyParameters($lines);
        $this->addQueryParameters($lines);
        $this->addUrlParameters($lines);
        $this->addSuccessResponses($lines);
        $this->addErrorResponses($lines);
        $this->addValidationErrorResponse($lines);
        $this->addAuthorizationErrorResponse($lines);
        $this->addAuthenticationTag($lines);
        $this->addGroupTag($lines);
        $this->addApiResourceTags($lines);
        $this->addRateLimitInfo($lines);
        $this->addAdditionalNotes($lines);

        $lines[] = ' */';

        // Clean output: remove redundant empty lines for Scribe compatibility
        return $this->cleanDocBlock(implode("\n", $lines));
    }

    private function addTitleAndDescription(array &$lines): void
    {
        $title = $this->generateTitle();
        $description = $this->generateDescription();

        $lines[] = " * {$title}";

        if ($description) {
            $lines[] = ' *';
            $lines[] = " * {$description}";
        }

        $lines[] = ' *';
    }

    private function addImplementationNotes(array &$lines): void
    {
        $notes = [];

        if (isset($this->analysis['body']['operations']['database']['transaction']) &&
            $this->analysis['body']['operations']['database']['transaction']) {
            $notes[] = '⚠️ This operation is executed within a database transaction with automatic rollback on failure.';
        }

        if (isset($this->analysis['body']['operations']['jobs']) &&
            ! empty($this->analysis['body']['operations']['jobs'])) {
            $jobCount = count($this->analysis['body']['operations']['jobs']);
            $notes[] = "🔄 {$jobCount} asynchronous background ".($jobCount === 1 ? 'job is' : 'jobs are').' dispatched.';
        }

        if (isset($this->analysis['body']['operations']['cache']) &&
            ! empty($this->analysis['body']['operations']['cache'])) {
            $cacheOps = implode(', ', array_unique($this->analysis['body']['operations']['cache']));
            $notes[] = "💾 Cache operations: {$cacheOps}.";
        }

        if (isset($this->analysis['pagination']['has_pagination']) &&
            $this->analysis['pagination']['has_pagination']) {
            $method = $this->analysis['pagination']['method'] ?? 'paginate';
            $perPage = $this->analysis['pagination']['per_page'] ?? 15;
            $notes[] = "📄 Results are paginated using {$method} (per page: {$perPage}).";
        }

        if (! empty($notes)) {
            foreach ($notes as $note) {
                $lines[] = " * {$note}";
            }
            $lines[] = ' *';
        }
    }

    private function addBodyParameters(array &$lines): void
    {
        $addedParams = [];
        
        // First, re-emit any existing body params from documentedMetadata
        foreach ($this->documentedMetadata['body_params'] as $name => $paramData) {
            if (isset($paramData['source']) && $paramData['source'] === 'phpdoc') {
                $addedParams[$name] = true;
                
                // Rebuild the bodyParam tag from captured data
                $required = ($paramData['required'] ?? true) ? 'required' : 'optional';
                $type = $paramData['type'] ?? 'string';
                $description = $paramData['description'] ?? '';
                $example = $paramData['example'] ?? null;
                
                $line = " * @bodyParam {$name} {$type} {$required}";
                if ($description) {
                    $line .= ' ' . $description;
                }
                if ($example !== null) {
                    $line .= ' Example: ' . $example;
                }
                $lines[] = $line;
            }
        }
        
        // Then add any new body params from analysis that aren't already documented
        if (isset($this->analysis['body_params']) && ! empty($this->analysis['body_params'])) {
            foreach ($this->analysis['body_params'] as $field => $param) {
                // Skip if already added or documented via PHPDoc or Attribute
                if (isset($addedParams[$field]) || $this->isBodyParamDocumented($field)) {
                    continue;
                }

                $required = $param['required'] ? 'required' : 'optional';
                $example = $param['example'] ?? $this->generateExampleForField($field, $param['type'] ?? 'string');
                $description = $param['description'] ?? Str::title(str_replace('_', ' ', $field));

                if (isset($param['constraints']) && ! empty($param['constraints'])) {
                    $constraints = implode(', ', $param['constraints']);
                    $description .= " ({$constraints})";
                }

                $lines[] = sprintf(
                    ' * @bodyParam %s %s %s %s Example: %s',
                    $field,
                    $param['type'] ?? 'string',
                    $required,
                    $description,
                    is_array($example) ? json_encode($example) : $example
                );
                $addedParams[$field] = true;
            }
        }
        
        if (! empty($addedParams)) {
            $lines[] = ' *';
        }
    }

    private function addQueryParameters(array &$lines): void
    {
        $addedParams = [];
        
        // First, re-emit any existing query params from documentedMetadata
        foreach ($this->documentedMetadata['query_params'] as $name => $paramData) {
            if (isset($paramData['source']) && $paramData['source'] === 'phpdoc') {
                $addedParams[$name] = true;
                
                // Rebuild the queryParam tag from captured data
                $required = ($paramData['required'] ?? false) ? 'required' : 'optional';
                $type = $paramData['type'] ?? 'string';
                $description = $paramData['description'] ?? '';
                $example = $paramData['example'] ?? null;
                
                $line = " * @queryParam {$name} {$type} {$required}";
                if ($description) {
                    $line .= ' ' . $description;
                }
                if ($example !== null) {
                    $line .= ' Example: ' . $example;
                }
                $lines[] = $line;
            }
        }
        
        // Then add any new query params from analysis that aren't already documented
        if (isset($this->analysis['query_params']) && ! empty($this->analysis['query_params'])) {
            foreach ($this->analysis['query_params'] as $param) {
                $field = $param['field'];
                // Skip if already added or documented via PHPDoc or Attribute
                if (isset($addedParams[$field]) || $this->isQueryParamDocumented($field)) {
                    continue;
                }

                $required = $param['required'] ? 'required' : 'optional';
                $example = $param['example'] ?? $this->generateExampleForField($field, $param['type'] ?? 'string');

                $lines[] = sprintf(
                    ' * @queryParam %s %s %s %s Example: %s',
                    $field,
                    $param['type'] ?? 'string',
                    $required,
                    $param['description'] ?? Str::title(str_replace('_', ' ', $field)),
                    $example
                );
                $addedParams[$field] = true;
            }
        }
        
        if (! empty($addedParams)) {
            $lines[] = ' *';
        }
    }

    private function addUrlParameters(array &$lines): void
    {
        $addedParams = [];

        // First, re-emit any existing url params from documentedMetadata
        foreach ($this->documentedMetadata['url_params'] as $name => $paramData) {
            if (isset($paramData['source']) && $paramData['source'] === 'phpdoc') {
                $addedParams[$name] = true;
                
                // Rebuild the urlParam tag
                $required = ($paramData['required'] ?? true) ? 'required' : 'optional';
                $description = $paramData['description'] ?? 'Resource identifier';
                $example = $paramData['example'] ?? '1';
                
                $lines[] = sprintf(
                    ' * @urlParam %s %s %s %s Example: %s',
                    $name,
                    $paramData['type'] ?? 'integer',
                    $required,
                    $description,
                    $example
                );
            }
        }

        // Then add any new url params from analysis that aren't already documented
        if (isset($this->analysis['url_params']) && ! empty($this->analysis['url_params'])) {
            foreach ($this->analysis['url_params'] as $param) {
                $field = $param['field'];
                
                // Skip if already added from existing docs
                if (isset($addedParams[$field])) {
                    continue;
                }

                $lines[] = sprintf(
                    ' * @urlParam %s %s required %s Example: %s',
                    $field,
                    $param['type'] ?? 'integer',
                    $param['description'] ?? 'Resource identifier',
                    $param['example'] ?? '1'
                );
                $addedParams[$field] = true;
            }
        }

        if (! empty($addedParams)) {
            $lines[] = ' *';
        }
    }

    private function addSuccessResponses(array &$lines): void
    {
        $hasSuccess = false;
        $addedStatusCodes = [];

        // First, re-emit any existing success responses from documentedMetadata
        foreach ($this->documentedMetadata['responses'] as $status => $responseData) {
            if ($status >= 200 && $status < 300 && isset($responseData['source']) && $responseData['source'] === 'phpdoc') {
                $hasSuccess = true;
                $addedStatusCodes[$status] = true;
                
                // Rebuild the response tag
                $type = $responseData['type'] ?? '';
                $content = $responseData['content'] ?? '';
                
                if ($type && $content) {
                    $lines[] = " * @response {$status} {$type}";
                    foreach (explode("\n", $content) as $contentLine) {
                        $lines[] = ' * '.$contentLine;
                    }
                } elseif ($content) {
                    $lines[] = " * @response {$status}";
                    foreach (explode("\n", $content) as $contentLine) {
                        $lines[] = ' * '.$contentLine;
                    }
                } else {
                    $lines[] = " * @response {$status}";
                }
            }
        }

        // Then add any new success responses from analysis that aren't already documented
        if (isset($this->analysis['responses']) && is_array($this->analysis['responses'])) {
            foreach ($this->analysis['responses'] as $response) {
                $statusCode = (int) ($response['status'] ?? 0);

                if ($statusCode >= 200 && $statusCode < 300) {
                    // Skip if already added from existing docs
                    if (isset($addedStatusCodes[$statusCode])) {
                        continue;
                    }

                    $hasSuccess = true;
                    $addedStatusCodes[$statusCode] = true;

                    // 204 No Content - just show the status, no body
                    if ($statusCode === 204) {
                        $lines[] = ' * @response 204';
                        continue;
                    }

                    $content = $response['example'] ?? $response['content'] ?? [];

                    // Check if content is a placeholder (e.g., __variable__) instead of real data
                    $isPlaceholder = is_array($content) && isset($content['__variable__']);
                    
                    if (! empty($content) && ! $isPlaceholder) {
                        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $lines[] = " * @response {$statusCode}";
                        foreach (explode("\n", $json) as $line) {
                            $lines[] = ' * '.$line;
                        }
                    } else {
                        // Use model-based example generation for placeholders or empty content
                        $example = $this->buildDefaultSuccessExample();
                        if (! empty($example)) {
                            $json = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            $lines[] = " * @response {$statusCode}";
                            foreach (explode("\n", $json) as $line) {
                                $lines[] = ' * '.$line;
                            }
                        } else {
                            $lines[] = " * @response {$statusCode}";
                        }
                    }
                }
            }
        }

        if (! $hasSuccess) {
            // Check if this is a destroy method
            $isDestroyOperation = ($this->analysis['operation_type'] ?? '') === 'destroy'
                || Str::contains($this->methodName, ['destroy', 'delete', 'remove']);

            if ($isDestroyOperation) {
                // Skip if 204 already documented
                if (! $this->isResponseDocumented(204)) {
                    $lines[] = ' * @response 204';
                }
                $lines[] = ' *';

                return;
            }

            // Skip default 200 if already documented
            if (! $this->isResponseDocumented(200)) {
                // No explicit 2xx responses found — generate a sensible default using models or inferred types
                $example = $this->buildDefaultSuccessExample();

                if (! empty($example)) {
                    $json = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $lines[] = ' * @response 200';
                    foreach (explode("\n", $json) as $line) {
                        $lines[] = ' * '.$line;
                    }
                    $lines[] = ' *';
                }
            }
        } else {
            $lines[] = ' *';
        }
    }

    private function buildDefaultSuccessExample(): array
    {
        $operation = $this->analysis['operation_type'] ?? $this->methodName;
        $isListOperation = $operation === 'index' || Str::contains($this->methodName, ['index', 'list', 'all']);
        $isDestroyOperation = $operation === 'destroy' || Str::contains($this->methodName, ['destroy', 'delete', 'remove']);

        // For destroy operations, return empty (204 has no content)
        if ($isDestroyOperation) {
            return [];
        }

        $models = $this->analysis['models'] ?? [];

        if (empty($models)) {
            // Fallback generic - return data directly without wrapper
            if ($isListOperation) {
                return [['id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]];
            }

            return ['id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        }

        // Use first model to craft example
        $modelClass = array_key_first($models);
        $modelInfo = $models[$modelClass] ?? [];

        $buildInstance = function () use ($modelInfo) {
            $item = [];

            if (isset($modelInfo['columns']) && is_array($modelInfo['columns']) && count($modelInfo['columns']) > 0) {
                foreach ($modelInfo['columns'] as $colName => $colInfo) {
                    if (is_int($colName) && is_string($colInfo)) {
                        $colName = $colInfo;
                        $colInfo = [];
                    }

                    $type = is_array($colInfo) && isset($colInfo['type']) ? $colInfo['type'] : 'string';
                    $example = $this->generateExampleForField($colName, $type);
                    $item[$colName] = is_string($example) && ($example === '[]' || $example === '{}') ? ($example === '[]' ? [] : (object) []) : $example;
                }
            } elseif (isset($modelInfo['fillable']) && is_array($modelInfo['fillable'])) {
                foreach ($modelInfo['fillable'] as $field) {
                    $item[$field] = $this->generateExampleForField($field, 'string');
                }
            } else {
                $item = ['id' => 1];
            }

            // Ensure timestamps
            if (! isset($item['created_at'])) {
                $item['created_at'] = date('Y-m-d H:i:s');
            }
            if (! isset($item['updated_at'])) {
                $item['updated_at'] = $item['created_at'];
            }

            return $item;
        };

        // Return data directly without wrapper keys
        if ($operation === 'index' || Str::contains($this->methodName, ['index', 'list', 'all'])) {
            return [$buildInstance()];
        }

        return $buildInstance();
    }

    private function addErrorResponses(array &$lines): void
    {
        $addedResponses = [];

        // First, re-emit any existing error responses from documentedMetadata
        foreach ($this->documentedMetadata['responses'] as $status => $responseData) {
            if ($status >= 400 && isset($responseData['source']) && $responseData['source'] === 'phpdoc') {
                $addedResponses[$status] = true;
                
                // Rebuild the response tag
                $type = $responseData['type'] ?? '';
                $content = $responseData['content'] ?? '';
                
                if ($type && $content) {
                    $lines[] = " * @response {$status} {$type}";
                    foreach (explode("\n", $content) as $contentLine) {
                        $lines[] = ' * '.$contentLine;
                    }
                } elseif ($content) {
                    $lines[] = " * @response {$status}";
                    foreach (explode("\n", $content) as $contentLine) {
                        $lines[] = ' * '.$contentLine;
                    }
                } else {
                    $lines[] = " * @response {$status}";
                }
            }
        }

        // Then add error responses from explicit response() calls that aren't already documented
        if (isset($this->analysis['responses']) && is_array($this->analysis['responses'])) {
            foreach ($this->analysis['responses'] as $response) {
                $statusCode = (int) ($response['status'] ?? 0);

                if ($statusCode >= 400) {
                    // Skip if already added from existing docs
                    if (isset($addedResponses[$statusCode])) {
                        continue;
                    }
                    
                    $key = $statusCode.'_'.md5(json_encode($response['content'] ?? ''));
                    if (isset($addedResponses[$key])) {
                        continue;
                    }
                    $addedResponses[$key] = true;

                    $content = $response['content'] ?? [];
                    $message = $response['message'] ?? $this->extractMessageFromContent($content) ?? $this->getDefaultErrorMessage($statusCode);

                    // Build the response content for display with proper formatting
                    if (is_array($content) && ! empty($content)) {
                        // Clean up variable placeholders for display
                        $displayContent = $this->cleanVariablePlaceholders($content);
                        $json = json_encode($displayContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $lines[] = sprintf(' * @response %s', $statusCode);
                        foreach (explode("\n", $json) as $jsonLine) {
                            $lines[] = ' * '.$jsonLine;
                        }
                    } else {
                        $lines[] = sprintf(' * @response %s {"message": "%s"}', $statusCode, addslashes($message));
                    }
                }
            }
        }

        // Then, add error responses from thrown exceptions
        if (isset($this->analysis['exceptions']) && ! empty($this->analysis['exceptions'])) {
            foreach ($this->analysis['exceptions'] as $exception) {
                if (isset($exception['status']) && $exception['status'] >= 400) {
                    $status = $exception['status'];
                    
                    // Skip if already added
                    if (isset($addedResponses[$status])) {
                        continue;
                    }
                    
                    $key = $status.'_exception';
                    if (isset($addedResponses[$key])) {
                        continue;
                    }
                    $addedResponses[$key] = true;

                    $message = $exception['message'] ?? $this->getDefaultErrorMessage($status);

                    $lines[] = sprintf(
                        ' * @response %s {"message": "%s"}',
                        $status,
                        addslashes($message)
                    );
                }
            }
        }

        if (! empty($addedResponses)) {
            $lines[] = ' *';
        }
    }

    private function extractMessageFromContent($content): ?string
    {
        if (is_array($content)) {
            return $content['message'] ?? $content['error'] ?? null;
        }

        return null;
    }

    private function addValidationErrorResponse(array &$lines): void
    {
        if (isset($this->analysis['validation_rules']) && ! empty($this->analysis['validation_rules'])) {
            $example = [
                'message' => 'The given data was invalid.',
                'errors' => [],
            ];

            foreach (array_slice($this->analysis['validation_rules'], 0, 3) as $field => $rules) {
                $errorMessage = $this->generateValidationErrorMessage($field, $rules);
                if ($errorMessage !== null) {
                    $example['errors'][$field] = [$errorMessage];
                }
            }

            // Only add 422 response if we have errors
            if (! empty($example['errors'])) {
                $json = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                $lines[] = ' * @response 422';
                $jsonLines = explode("\n", $json);
                foreach ($jsonLines as $line) {
                    $lines[] = ' * '.$line;
                }
                $lines[] = ' *';
            }
        }
    }

    /**
     * Generate appropriate validation error message based on actual rules.
     */
    private function generateValidationErrorMessage(string $field, $rules): ?string
    {
        $rulesArray = is_string($rules) ? explode('|', $rules) : (is_array($rules) ? $rules : []);
        $normalizedRules = [];

        foreach ($rulesArray as $rule) {
            $ruleName = is_string($rule) ? explode(':', $rule)[0] : (is_object($rule) ? class_basename($rule) : '');
            $normalizedRules[] = strtolower($ruleName);
        }

        $fieldName = str_replace('_', ' ', $field);

        // If field is nullable or sometimes, don't show "required" error
        $isOptional = in_array('nullable', $normalizedRules) || in_array('sometimes', $normalizedRules);

        // Check for specific validation rules and generate appropriate messages
        if (in_array('required', $normalizedRules) && ! $isOptional) {
            return 'The '.$fieldName.' field is required.';
        }

        if (in_array('email', $normalizedRules)) {
            return 'The '.$fieldName.' must be a valid email address.';
        }

        if (in_array('numeric', $normalizedRules) || in_array('integer', $normalizedRules)) {
            return 'The '.$fieldName.' must be a number.';
        }

        if (in_array('string', $normalizedRules)) {
            return 'The '.$fieldName.' must be a string.';
        }

        if (in_array('array', $normalizedRules)) {
            return 'The '.$fieldName.' must be an array.';
        }

        if (in_array('boolean', $normalizedRules) || in_array('bool', $normalizedRules)) {
            return 'The '.$fieldName.' must be true or false.';
        }

        if (in_array('date', $normalizedRules)) {
            return 'The '.$fieldName.' is not a valid date.';
        }

        if (in_array('exists', $normalizedRules)) {
            return 'The selected '.$fieldName.' is invalid.';
        }

        if (in_array('unique', $normalizedRules)) {
            return 'The '.$fieldName.' has already been taken.';
        }

        // Find min/max rules
        foreach ($rulesArray as $rule) {
            if (is_string($rule)) {
                if (str_starts_with($rule, 'min:')) {
                    $value = explode(':', $rule)[1] ?? '';

                    return 'The '.$fieldName.' must be at least '.$value.' characters.';
                }
                if (str_starts_with($rule, 'max:')) {
                    $value = explode(':', $rule)[1] ?? '';

                    return 'The '.$fieldName.' may not be greater than '.$value.' characters.';
                }
            }
        }

        // For optional fields that have no specific error-generating rules, skip
        if ($isOptional) {
            return null;
        }

        // Default fallback
        return 'The '.$fieldName.' field is invalid.';
    }

    private function addAuthorizationErrorResponse(array &$lines): void
    {
        if (isset($this->analysis['authorization']) && $this->analysis['authorization']['required']) {
            $lines[] = ' * @response 403 {"message": "This action is unauthorized."}';
            $lines[] = ' *';
        }
    }

    private function addAuthenticationTag(array &$lines): void
    {
        $requiresAuth = false;
        $guard = null;

        // Check middleware for authentication
        if (isset($this->analysis['middleware']) && $this->analysis['middleware']['requires_auth']) {
            $requiresAuth = true;
            $guard = $this->analysis['middleware']['auth_guard'] ?? null;
        }

        if (isset($this->analysis['authorization']) && $this->analysis['authorization']['required']) {
            $requiresAuth = true;
        }

        if (isset($this->analysis['body']['operations']['auth']) &&
            ! empty($this->analysis['body']['operations']['auth'])) {
            $requiresAuth = true;
        }

        // Skip if already documented via PHPDoc or Attribute (Smart Completion)
        if ($this->documentedMetadata['authenticated'] === true) {
            return;
        }

        if ($requiresAuth) {
            $lines[] = ' * @authenticated';
            // Skip Authorization header if already documented
            if (! isset($this->documentedMetadata['headers']['Authorization'])) {
                if ($guard) {
                    $lines[] = ' * @header Authorization Bearer {token} (Guard: '.$guard.')';
                } else {
                    $lines[] = ' * @header Authorization Bearer {token}';
                }
            }
        }
    }

    private function addGroupTag(array &$lines): void
    {
        // Use existing group from PHPDoc, Attribute, or generate new one (priority order)
        $groupName = $this->documentedMetadata['group'] 
            ?? $this->existingGroup 
            ?? $this->generateGroupName();
        $lines[] = " * @group {$groupName}";
    }

    private function existingDocContainsDetailedTags(string $doc): bool
    {
        return (bool) preg_match('/@queryParam|@bodyParam|@response|@authenticated|@header|@urlParam|@bodyParam/', $doc);
    }

    private function extractExistingGroup(?string $doc): ?string
    {
        if (! $doc) {
            return null;
        }

        if (preg_match('/@group\s+(.+)$/m', $doc, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function addApiResourceTags(array &$lines): void
    {
        if (isset($this->analysis['api_resource'])) {
            $lines[] = ' * @apiResource '.$this->analysis['api_resource']['name'];
            $lines[] = ' * @apiResourceModel '.$this->analysis['api_resource']['model'];
        }
    }

    private function addRateLimitInfo(array &$lines): void
    {
        if (isset($this->analysis['rate_limiting']['enabled']) &&
            $this->analysis['rate_limiting']['enabled']) {
            $maxAttempts = $this->analysis['rate_limiting']['max_attempts'] ?? 'unknown';
            $decayMinutes = $this->analysis['rate_limiting']['decay_minutes'] ?? 'unknown';

            $lines[] = ' * @response 429 {"message": "Too many requests"}';
            $lines[] = ' *';
            $lines[] = " * ⏱️ Rate limited to {$maxAttempts} requests per {$decayMinutes} minutes.";
            $lines[] = ' *';
        }
    }

    private function addAdditionalNotes(array &$lines): void
    {
        $additionalNotes = [];

        if (isset($this->analysis['body']['operations']['mail']) &&
            ! empty($this->analysis['body']['operations']['mail'])) {
            $additionalNotes[] = '📧 Email notifications are sent.';
        }

        if (isset($this->analysis['body']['operations']['notification']) &&
            ! empty($this->analysis['body']['operations']['notification'])) {
            $additionalNotes[] = '🔔 Push notifications are sent.';
        }

        if (isset($this->analysis['body']['operations']['event']) &&
            ! empty($this->analysis['body']['operations']['event'])) {
            $additionalNotes[] = '📡 System events are fired.';
        }

        if (isset($this->analysis['body']['operations']['broadcast']) &&
            ! empty($this->analysis['body']['operations']['broadcast'])) {
            $additionalNotes[] = '📡 Real-time broadcasts are sent.';
        }

        if (! empty($additionalNotes)) {
            foreach ($additionalNotes as $note) {
                $lines[] = " * {$note}";
            }
        }
    }

    private function generateTitle(): string
    {
        // Preserve existing title if present
        if (! empty($this->documentedMetadata['title'])) {
            return $this->documentedMetadata['title'];
        }
        
        $methodName = $this->methodName;
        $titles = [
            'index' => 'List all resources',
            'show' => 'Display the specified resource',
            'store' => 'Store a newly created resource in storage',
            'update' => 'Update the specified resource in storage',
            'destroy' => 'Remove the specified resource from storage',
            'create' => 'Show the form for creating a new resource',
            'edit' => 'Show the form for editing the specified resource',
        ];

        if (isset($titles[$methodName])) {
            return $titles[$methodName];
        }

        if (Str::contains($methodName, ['index', 'list', 'all'])) {
            return 'List resources';
        }
        if (Str::contains($methodName, ['show', 'find', 'get'])) {
            return 'Get resource details';
        }
        if (Str::contains($methodName, ['store', 'create', 'save'])) {
            return 'Create new resource';
        }
        if (Str::contains($methodName, ['update', 'edit', 'modify'])) {
            return 'Update resource';
        }
        if (Str::contains($methodName, ['destroy', 'delete', 'remove'])) {
            return 'Delete resource';
        }

        return 'Process request';
    }

    private function generateDescription(): string
    {
        // Preserve existing description if present
        if (! empty($this->documentedMetadata['description'])) {
            return $this->documentedMetadata['description'];
        }
        
        $description = [];

        if (isset($this->analysis['operation_type'])) {
            $operation = $this->analysis['operation_type'];
            $descriptions = [
                'index' => 'Retrieves a paginated list of resources with optional filtering and sorting.',
                'show' => 'Retrieves detailed information about a specific resource.',
                'store' => 'Creates a new resource with validated data.',
                'update' => 'Updates an existing resource with validated data.',
                'destroy' => 'Permanently removes the specified resource.',
            ];

            if (isset($descriptions[$operation])) {
                $description[] = $descriptions[$operation];
            }
        }

        if (isset($this->analysis['models'])) {
            $modelNames = array_map('class_basename', array_keys($this->analysis['models']));
            if (! empty($modelNames)) {
                $description[] = 'Works with '.implode(', ', $modelNames).' models.';
            }
        }

        return implode(' ', $description);
    }

    private function generateExampleForField(string $field, string $type): string
    {
        $fieldLower = strtolower($field);

        // If the type is explicitly integer, return integer examples
        if ($type === 'integer') {
            // Check for specific integer field patterns
            if (str_ends_with($fieldLower, '_id')) {
                return (string) $this->faker->numberBetween(1, 100);
            }
            if (str_ends_with($fieldLower, '_type') || str_contains($fieldLower, 'type')) {
                return (string) $this->faker->numberBetween(1, 10);
            }
            if (str_contains($fieldLower, 'state') || str_contains($fieldLower, 'status')) {
                return (string) $this->faker->numberBetween(0, 5);
            }
            if (str_contains($fieldLower, 'index')) {
                return (string) $this->faker->numberBetween(0, 20);
            }
            if (str_contains($fieldLower, 'week')) {
                return (string) $this->faker->numberBetween(1, 52);
            }

            return (string) $this->faker->numberBetween(1, 100);
        }

        // If the type is explicitly array, return empty array
        if ($type === 'array') {
            return '[]';
        }

        $patterns = [
            '/email/' => fn () => $this->faker->email(),
            '/name/' => fn () => $this->faker->name(),
            '/first_name/' => fn () => $this->faker->firstName(),
            '/last_name/' => fn () => $this->faker->lastName(),
            '/phone|mobile|tel/' => fn () => $this->faker->phoneNumber(),
            '/address|street/' => fn () => $this->faker->address(),
            '/city/' => fn () => $this->faker->city(),
            '/zip|postcode|postal_code/' => fn () => $this->faker->postcode(),
            '/country/' => fn () => $this->faker->country(),
            '/date/' => fn () => $this->faker->date(),
            '/title|subject/' => fn () => $this->faker->sentence(3),
            '/description|bio|about/' => fn () => $this->faker->paragraph(),
            '/content|body|message/' => fn () => $this->faker->paragraphs(2, true),
            '/price|amount|cost|total/' => fn () => $this->faker->randomFloat(2, 1, 1000),
            '/quantity|count|number/' => fn () => $this->faker->numberBetween(1, 100),
            '/url|website|link/' => fn () => $this->faker->url(),
            '/image|photo|avatar/' => fn () => $this->faker->imageUrl(),
        ];

        foreach ($patterns as $pattern => $generator) {
            if (preg_match($pattern, $fieldLower)) {
                $value = $generator();

                return is_string($value) ? $value : json_encode($value);
            }
        }

        switch ($type) {
            case 'integer':
                return (string) $this->faker->numberBetween(1, 100);
            case 'number':
                return (string) $this->faker->randomFloat(2, 1, 100);
            case 'boolean':
                return $this->faker->boolean() ? 'true' : 'false';
            case 'array':
                return '[]';
            case 'object':
                return '{}';
            case 'file':
                return 'document.pdf';
            default:
                return $this->faker->word();
        }
    }

    private function generateGroupName(): string
    {
        $controllerName = class_basename($this->controllerClass);
        $controllerName = str_replace('Controller', '', $controllerName);

        if ($this->config['output']['group_strategy'] === 'namespace') {
            $parts = explode('\\', $this->controllerClass);
            $namespace = $parts[count($parts) - 2] ?? 'API';

            return $namespace;
        }

        return $controllerName ?: ($this->config['output']['default_group'] ?? 'API');
    }

    private function getDefaultErrorMessage(int $status): string
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Validation Error',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];

        return $messages[$status] ?? 'An error occurred';
    }

    /**
     * Clean up variable placeholders in response content for display
     */
    private function cleanVariablePlaceholders(array $content): array
    {
        $cleaned = [];

        foreach ($content as $key => $value) {
            if (is_array($value)) {
                // Check if it's a variable placeholder
                if (isset($value['__variable__'])) {
                    $varName = $value['__variable__'];
                    // Generate a sensible placeholder based on variable name
                    $cleaned[$key] = $this->generatePlaceholderForVariable($varName, $key);
                } else {
                    $cleaned[$key] = $this->cleanVariablePlaceholders($value);
                }
            } elseif (is_string($value) && preg_match('/^\{\$\w+/', $value)) {
                // String placeholder like {$variable} or {$var['key']}
                $varName = preg_replace('/^\{\$(\w+).*/', '$1', $value);
                $cleaned[$key] = $this->generatePlaceholderForVariable($varName, $key);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Generate a sensible placeholder value for a variable
     */
    private function generatePlaceholderForVariable(string $varName, string $contextKey): mixed
    {
        // If the variable corresponds to a model, generate a model example
        $modelKeys = ['sessione', 'session', 'patient', 'user', 'model', 'item', 'resource'];
        $varLower = strtolower($varName);

        foreach ($modelKeys as $modelKey) {
            if (str_contains($varLower, $modelKey)) {
                // Return an object placeholder
                return ['id' => 1, '...' => '...'];
            }
        }

        // For batch/job related
        if (str_contains($varLower, 'batch') || str_contains($varLower, 'job') || str_contains($varLower, 'result')) {
            if (str_contains($contextKey, 'error')) {
                return 'Error message';
            }

            return 'job-id-123';
        }

        // Default placeholder
        return '...';
    }

    private function mergeWithExisting(array &$lines): void
    {
        if (! $this->existingDoc || ! ($this->config['output']['preserve_existing'] ?? false)) {
            return;
        }

        $existingLines = explode("\n", $this->existingDoc);
        $existingTags = [];
        $existingText = [];

        foreach ($existingLines as $line) {
            $line = trim($line);

            if (preg_match('/^\*\s*@(\w+)/', $line, $matches)) {
                $tag = $matches[1];
                $existingTags[$tag][] = $line;
            } elseif (! in_array($line, ['/**', '*/']) && $line !== '*') {
                $existingText[] = $line;
            }
        }

        $mergeStrategy = $this->config['output']['merge_strategy'] ?? 'smart';

        if ($mergeStrategy === 'merge' || $mergeStrategy === 'smart') {
            foreach ($existingText as $text) {
                if (! in_array($text, $lines)) {
                    array_splice($lines, 2, 0, [$text]);
                }
            }
        }
    }

    /**
     * Clean the DocBlock output to ensure Scribe compatibility
     * - Removes double empty lines
     * - Ensures proper spacing between sections
     * - Formats for consistent output
     */
    private function cleanDocBlock(string $docBlock): string
    {
        $lines = explode("\n", $docBlock);
        $cleaned = [];
        $previousWasEmpty = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Check if this is an empty doc line (just " *")
            $isEmptyLine = $trimmed === '*';

            // Skip if this is a consecutive empty line
            if ($isEmptyLine && $previousWasEmpty) {
                continue;
            }

            $cleaned[] = $line;
            $previousWasEmpty = $isEmptyLine;
        }

        // Remove trailing empty line before closing */
        $count = count($cleaned);
        if ($count >= 2 && trim($cleaned[$count - 2]) === '*') {
            array_splice($cleaned, $count - 2, 1);
        }

        return implode("\n", $cleaned);
    }
}
