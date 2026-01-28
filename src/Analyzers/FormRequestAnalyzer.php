<?php

namespace Badass\LazyDocs\Analyzers;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use ReflectionClass;

class FormRequestAnalyzer
{
    private array $parameters;

    private array $config;

    private array $formRequestCache = [];

    public function __construct(array $parameters, array $config = [])
    {
        $this->parameters = $parameters;
        $this->config = $config;
    }

    public function analyze(): array
    {
        $analysis = [
            'form_requests' => [],
            'validation_rules' => [],
            'body_params' => [],
            'query_params' => [],
            'validation_messages' => [],
            'validation_attributes' => [],
        ];

        foreach ($this->parameters as $param) {
            if (isset($param['form_request'])) {
                $requestClass = $param['request_class'] ?? null;

                if ($requestClass && class_exists($requestClass)) {
                    $formRequestAnalysis = $this->analyzeFormRequest($requestClass);

                    $analysis['form_requests'][$param['name']] = array_merge(
                        $formRequestAnalysis,
                        ['request_class' => $requestClass]
                    );

                    $analysis['validation_rules'] = array_merge(
                        $analysis['validation_rules'],
                        $formRequestAnalysis['rules']
                    );

                    $analysis['validation_messages'] = array_merge(
                        $analysis['validation_messages'],
                        $formRequestAnalysis['messages']
                    );

                    $analysis['validation_attributes'] = array_merge(
                        $analysis['validation_attributes'],
                        $formRequestAnalysis['attributes']
                    );

                    foreach ($formRequestAnalysis['rules'] as $field => $rules) {
                        $analysis['body_params'][$field] = $this->convertRulesToParam($field, $rules);
                    }
                }
            }
        }

        return $analysis;
    }

    private function analyzeFormRequest(string $formRequestClass): array
    {
        if (isset($this->formRequestCache[$formRequestClass])) {
            return $this->formRequestCache[$formRequestClass];
        }

        $reflection = new ReflectionClass($formRequestClass);

        $analysis = [
            'class' => $formRequestClass,
            'rules' => $this->extractRules($reflection),
            'authorize' => $this->extractAuthorization($reflection),
            'messages' => $this->extractMessages($reflection),
            'attributes' => $this->extractAttributes($reflection),
            'custom_validation' => $this->extractCustomValidation($reflection),
        ];

        $this->formRequestCache[$formRequestClass] = $analysis;

        return $analysis;
    }

    private function extractRules(ReflectionClass $reflection): array
    {
        if (! $reflection->hasMethod('rules')) {
            return [];
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $rulesMethod = $reflection->getMethod('rules');

            if ($rulesMethod->getNumberOfRequiredParameters() === 0) {
                $rules = $rulesMethod->invoke($instance);

                return $this->normalizeRules($rules);
            }
        } catch (\Exception $e) {
        }

        return $this->extractRulesFromSource($reflection);
    }

    private function extractRulesFromSource(ReflectionClass $reflection): array
    {
        $filePath = $reflection->getFileName();
        if (! $filePath || ! file_exists($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);
        $parser = (new ParserFactory)->createForHostVersion();

        try {
            $ast = $parser->parse($code);
            $nodeFinder = new NodeFinder;

            $rulesMethod = $nodeFinder->findFirst($ast, function (Node $node) {
                return $node instanceof Node\Stmt\ClassMethod &&
                       $node->name->toString() === 'rules';
            });

            if (! $rulesMethod) {
                return [];
            }

            $returnVisitor = new RulesVisitor;
            $traverser = new NodeTraverser;
            $traverser->addVisitor($returnVisitor);
            $traverser->traverse([$rulesMethod]);

            $rules = $returnVisitor->getRules();

            return $this->normalizeRules($rules);

        } catch (\Exception $e) {
            return [];
        }
    }

    private function normalizeRules($rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                $normalized[$field] = explode('|', $rule);
            } elseif (is_array($rule)) {
                $normalized[$field] = $rule;
            } elseif ($rule instanceof Rule) {
                $normalized[$field] = [$this->ruleToString($rule)];
            } else {
                $normalized[$field] = [(string) $rule];
            }
        }

        return $normalized;
    }

    private function ruleToString($rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if ($rule instanceof Rule) {
            return $this->parseRuleObject($rule);
        }

        return 'unknown';
    }

    private function parseRuleObject(Rule $rule): string
    {
        $reflection = new ReflectionClass($rule);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($rule);

            if (is_string($value) && ! empty($value)) {
                return $value;
            }
        }

        return 'custom_rule';
    }

    private function extractAuthorization(ReflectionClass $reflection): array
    {
        if (! $reflection->hasMethod('authorize')) {
            return ['required' => false, 'custom' => false];
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $authorizeMethod = $reflection->getMethod('authorize');

            if ($authorizeMethod->getNumberOfRequiredParameters() === 0) {
                $result = $authorizeMethod->invoke($instance);

                return [
                    'required' => (bool) $result,
                    'custom' => true,
                    'result' => $result,
                ];
            }

            return ['required' => true, 'custom' => true];

        } catch (\Exception $e) {
            return ['required' => false, 'custom' => false];
        }
    }

    private function extractMessages(ReflectionClass $reflection): array
    {
        if (! $reflection->hasMethod('messages')) {
            return [];
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $messagesMethod = $reflection->getMethod('messages');

            if ($messagesMethod->getNumberOfRequiredParameters() === 0) {
                $messages = $messagesMethod->invoke($instance);

                return is_array($messages) ? $messages : [];
            }
        } catch (\Exception $e) {
        }

        return [];
    }

    private function extractAttributes(ReflectionClass $reflection): array
    {
        if (! $reflection->hasMethod('attributes')) {
            return [];
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $attributesMethod = $reflection->getMethod('attributes');

            if ($attributesMethod->getNumberOfRequiredParameters() === 0) {
                $attributes = $attributesMethod->invoke($instance);

                return is_array($attributes) ? $attributes : [];
            }
        } catch (\Exception $e) {
        }

        return [];
    }

    private function extractCustomValidation(ReflectionClass $reflection): array
    {
        $custom = [];

        if ($reflection->hasMethod('withValidator')) {
            $custom[] = 'withValidator';
        }

        if ($reflection->hasMethod('after')) {
            $custom[] = 'after';
        }

        if ($reflection->hasMethod('prepareForValidation')) {
            $custom[] = 'prepareForValidation';
        }

        return $custom;
    }

    private function convertRulesToParam(string $field, array $rules): array
    {
        return [
            'field' => $field,
            'type' => $this->inferTypeFromRules($rules),
            'required' => $this->isRequired($rules),
            'nullable' => $this->isNullable($rules),
            'rules' => $rules,
            'description' => $this->generateDescription($field, $rules),
            'example' => $this->generateExample($field, $rules),
            'constraints' => $this->extractConstraints($rules),
        ];
    }

    private function inferTypeFromRules(array $rules): string
    {
        $typeMap = [
            'integer' => 'integer',
            'int' => 'integer',
            'numeric' => 'number',
            'float' => 'number',
            'double' => 'number',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'array' => 'array',
            'date' => 'string',
            'email' => 'string',
            'string' => 'string',
            'url' => 'string',
            'ip' => 'string',
            'json' => 'string',
            'file' => 'file',
            'image' => 'file',
        ];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $rule = strtolower($rule);

                foreach ($typeMap as $pattern => $type) {
                    if ($rule === $pattern || str_starts_with($rule, $pattern.':')) {
                        return $type;
                    }
                }

                if (str_starts_with($rule, 'exists:') || str_starts_with($rule, 'unique:')) {
                    return 'integer';
                }

                if (str_starts_with($rule, 'in:')) {
                    return 'string';
                }

                if (str_starts_with($rule, 'mimes:') || str_starts_with($rule, 'mimetypes:')) {
                    return 'file';
                }
            }
        }

        return 'string';
    }

    private function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $rule = strtolower($rule);
                if ($rule === 'required' || str_starts_with($rule, 'required_')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isNullable(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && strtolower($rule) === 'nullable') {
                return true;
            }
        }

        return false;
    }

    private function generateDescription(string $field, array $rules): string
    {
        $parts = [];

        if ($this->isRequired($rules)) {
            $parts[] = 'required';
        } elseif ($this->isNullable($rules)) {
            $parts[] = 'optional';
        }

        $type = $this->inferTypeFromRules($rules);
        $parts[] = $type;

        $constraints = $this->extractConstraints($rules);
        if (! empty($constraints)) {
            $parts = array_merge($parts, $constraints);
        }

        if (empty($parts)) {
            return Str::title(str_replace('_', ' ', $field));
        }

        return implode(', ', array_filter($parts));
    }

    private function generateExample(string $field, array $rules): string
    {
        $faker = \Faker\Factory::create();
        $fieldLower = strtolower($field);
        $type = $this->inferTypeFromRules($rules);

        $patterns = [
            '/email/' => fn () => $faker->email(),
            '/name/' => fn () => $faker->name(),
            '/first_name/' => fn () => $faker->firstName(),
            '/last_name/' => fn () => $faker->lastName(),
            '/phone|mobile|tel/' => fn () => $faker->phoneNumber(),
            '/address|street/' => fn () => $faker->streetAddress(),
            '/city/' => fn () => $faker->city(),
            '/state|province/' => fn () => $faker->state(),
            '/zip|postcode|postal_code/' => fn () => $faker->postcode(),
            '/country/' => fn () => $faker->country(),
            '/date/' => fn () => $faker->date(),
            '/time/' => fn () => $faker->time(),
            '/datetime|timestamp/' => fn () => $faker->dateTime()->format('Y-m-d H:i:s'),
            '/title|subject/' => fn () => $faker->sentence(3),
            '/description|bio|about/' => fn () => $faker->paragraph(),
            '/content|body|message/' => fn () => $faker->paragraphs(2, true),
            '/price|amount|cost|total/' => fn () => $faker->randomFloat(2, 1, 1000),
            '/quantity|count|number/' => fn () => $faker->numberBetween(1, 100),
            '/percentage|rate/' => fn () => $faker->randomFloat(2, 0, 100),
            '/url|link|website/' => fn () => $faker->url(),
            '/username|login/' => fn () => $faker->userName(),
            '/password/' => fn () => 'Secret123!',
        ];

        foreach ($patterns as $pattern => $generator) {
            if (preg_match($pattern, $fieldLower)) {
                $value = $generator();

                return is_string($value) ? $value : json_encode($value);
            }
        }

        switch ($type) {
            case 'integer':
                $min = $this->getMinValue($rules);
                $max = $this->getMaxValue($rules);
                $example = $faker->numberBetween($min ?? 1, $max ?? 100);

                return (string) $example;

            case 'number':
                $min = $this->getMinValue($rules) ?: 0.01;
                $max = $this->getMaxValue($rules) ?: 1000;
                $example = $faker->randomFloat(2, $min, $max);

                return (string) $example;

            case 'boolean':
                return $faker->boolean() ? 'true' : 'false';

            case 'array':
                return '[]';

            case 'file':
                return 'file.pdf';

            default:
                $min = $this->getMinValue($rules, true);
                $max = $this->getMaxValue($rules, true);

                if ($min !== null && $max !== null) {
                    $length = $faker->numberBetween($min, $max);

                    return $faker->text($length);
                } elseif ($min !== null) {
                    return $faker->text($min);
                } elseif ($max !== null) {
                    $length = min($faker->numberBetween(10, $max), $max);

                    return $faker->text($length);
                }

                return $faker->word();
        }
    }

    private function extractConstraints(array $rules): array
    {
        $constraints = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $rule = strtolower($rule);

                if (preg_match('/^min:(\d+)$/', $rule, $matches)) {
                    $constraints[] = "min: {$matches[1]}";
                } elseif (preg_match('/^max:(\d+)$/', $rule, $matches)) {
                    $constraints[] = "max: {$matches[1]}";
                } elseif (preg_match('/^between:(\d+),(\d+)$/', $rule, $matches)) {
                    $constraints[] = "between {$matches[1]} and {$matches[2]}";
                } elseif (preg_match('/^size:(\d+)$/', $rule, $matches)) {
                    $constraints[] = "size: {$matches[1]}";
                } elseif (preg_match('/^in:(.+)$/', $rule, $matches)) {
                    $values = explode(',', $matches[1]);
                    $constraints[] = 'one of: '.implode(', ', array_slice($values, 0, 3)).(count($values) > 3 ? '...' : '');
                } elseif (preg_match('/^exists:(.+)$/', $rule, $matches)) {
                    $constraints[] = 'must exist';
                } elseif (preg_match('/^unique:(.+)$/', $rule, $matches)) {
                    $constraints[] = 'must be unique';
                } elseif ($rule === 'email') {
                    $constraints[] = 'valid email';
                } elseif ($rule === 'url') {
                    $constraints[] = 'valid URL';
                } elseif ($rule === 'ip') {
                    $constraints[] = 'valid IP address';
                } elseif ($rule === 'json') {
                    $constraints[] = 'valid JSON';
                } elseif ($rule === 'date') {
                    $constraints[] = 'valid date';
                } elseif (preg_match('/^date_format:(.+)$/', $rule, $matches)) {
                    $constraints[] = "date format: {$matches[1]}";
                } elseif (preg_match('/^mimes:(.+)$/', $rule, $matches)) {
                    $constraints[] = "file types: {$matches[1]}";
                } elseif (preg_match('/^mimetypes:(.+)$/', $rule, $matches)) {
                    $constraints[] = "MIME types: {$matches[1]}";
                }
            }
        }

        return array_unique($constraints);
    }

    private function getMinValue(array $rules, bool $forString = false): ?int
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $rule = strtolower($rule);

                if ($forString && preg_match('/^min:(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                } elseif (! $forString && preg_match('/^min:(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                } elseif (preg_match('/^between:(\d+),(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                } elseif (preg_match('/^size:(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    private function getMaxValue(array $rules, bool $forString = false): ?int
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $rule = strtolower($rule);

                if ($forString && preg_match('/^max:(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                } elseif (! $forString && preg_match('/^max:(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                } elseif (preg_match('/^between:(\d+),(\d+)$/', $rule, $matches)) {
                    return (int) $matches[2];
                } elseif (preg_match('/^size:(\d+)$/', $rule, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }
}
