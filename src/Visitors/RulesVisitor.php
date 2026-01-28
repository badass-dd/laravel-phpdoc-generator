<?php

namespace Badass\LazyDocs\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class RulesVisitor extends NodeVisitorAbstract
{
    private array $rules = [];

    private bool $inRulesMethod = false;

    private array $currentRules = [];

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

        return null;
    }

    public function getRules(): array
    {
        return $this->rules;
    }
}
