<?php

namespace Badass\LazyDocs\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;

abstract class BaseAnalyzer
{
    protected array $ast;

    protected string $controllerClass;

    protected string $methodName;

    protected NodeFinder $nodeFinder;

    public function __construct(array $ast, string $controllerClass, string $methodName)
    {
        $this->ast = $ast;
        $this->controllerClass = $controllerClass;
        $this->methodName = $methodName;
        $this->nodeFinder = new NodeFinder;
    }

    abstract public function analyze(): array;

    protected function findMethodNode(): ?Node\Stmt\ClassMethod
    {
        return $this->nodeFinder->findFirst($this->ast, function (Node $node) {
            return $node instanceof Node\Stmt\ClassMethod &&
                   $node->name->toString() === $this->methodName;
        });
    }

    protected function extractFormRequestParameters(Node\Stmt\ClassMethod $methodNode): array
    {
        $parameters = [];

        foreach ($methodNode->params as $param) {
            if ($param->type instanceof Node\Name) {
                $typeName = $param->type->toString();

                if (is_subclass_of($typeName, \Illuminate\Foundation\Http\FormRequest::class)) {
                    $parameters = array_merge(
                        $parameters,
                        $this->analyzeFormRequest($typeName)
                    );
                }
            }
        }

        return $parameters;
    }

    private function analyzeFormRequest(string $formRequestClass): array
    {
        $rules = [];

        try {
            $reflection = new \ReflectionClass($formRequestClass);

            if ($reflection->hasMethod('rules')) {
                $instance = $reflection->newInstanceWithoutConstructor();
                $rules = $instance->rules();

                return $this->normalizeValidationRules($rules);
            }
        } catch (\Exception $e) {
        }

        return [];
    }

    private function normalizeValidationRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                $normalized[$field] = explode('|', $rule);
            } elseif (is_array($rule)) {
                $normalized[$field] = $rule;
            } else {
                $normalized[$field] = [$rule];
            }
        }

        return $normalized;
    }
}
