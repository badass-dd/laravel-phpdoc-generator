<?php

namespace Badass\LazyDocs\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;

class InlineValidationAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $validationRules = [];
        $printer = new \PhpParser\PrettyPrinter\Standard;

        $calls = (new NodeFinder)->findInstanceOf($methodNode, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

            if ($methodName === 'validate') {
                // ensure called on $request
                if ($call->var instanceof Node\Expr\Variable && is_string($call->var->name) && $call->var->name === 'request') {
                    if (isset($call->args[0])) {
                        $arg = $call->args[0]->value;
                        if ($arg instanceof Node\Expr\Array_) {
                            foreach ($arg->items as $item) {
                                if ($item instanceof Node\Expr\ArrayItem) {
                                    $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                                    if (! $key) {
                                        continue;
                                    }

                                    $rules = [];
                                    $value = $item->value;

                                    if ($value instanceof Node\Scalar\String_) {
                                        $rules = explode('|', $value->value);
                                    } elseif ($value instanceof Node\Expr\Array_) {
                                        foreach ($value->items as $r) {
                                            if ($r instanceof Node\Expr\ArrayItem) {
                                                if ($r->value instanceof Node\Scalar\String_) {
                                                    $rules[] = $r->value->value;
                                                } else {
                                                    $rules[] = $printer->prettyPrintExpr($r->value);
                                                }
                                            }
                                        }
                                    } else {
                                        $rules[] = $printer->prettyPrintExpr($value);
                                    }

                                    $validationRules[$key] = $rules;
                                }
                            }
                        }
                    }
                }
            }
        }

        $analysis = [];

        if (! empty($validationRules)) {
            $analysis['validation_rules'] = $validationRules;

            // Determine if these are query params (index/list methods) or body params
            $isQuery = false;
            if (stripos($this->methodName, 'index') !== false || stripos($this->methodName, 'list') !== false) {
                $isQuery = true;
            }

            foreach ($validationRules as $field => $rules) {
                $param = [
                    'field' => $field,
                    'type' => $this->inferTypeFromRules($rules),
                    'required' => $this->rulesRequireField($rules),
                    'description' => ucfirst(str_replace('_', ' ', $field)),
                    'example' => null,
                ];

                if ($isQuery) {
                    $analysis['query_params'][] = $param;
                } else {
                    $analysis['body_params'][$field] = $param;
                }
            }
        }

        return $analysis;
    }

    private function rulesRequireField(array $rules): bool
    {
        foreach ($rules as $r) {
            if (str_contains($r, 'required')) {
                return true;
            }
        }

        return false;
    }

    private function inferTypeFromRules(array $rules): string
    {
        foreach ($rules as $r) {
            if (str_contains($r, 'integer') || str_contains($r, 'numeric')) {
                return 'integer';
            }
            // exists:table,column typically refers to an ID (integer)
            if (str_starts_with($r, 'exists:')) {
                return 'integer';
            }
            if (str_contains($r, 'array') || str_contains($r, 'json')) {
                return 'array';
            }
            if (str_contains($r, 'boolean')) {
                return 'boolean';
            }
            // date/datetime rules
            if ($r === 'date' || str_starts_with($r, 'date_format:') || str_starts_with($r, 'after:') || str_starts_with($r, 'before:')) {
                return 'datetime';
            }
        }

        return 'string';
    }
}
