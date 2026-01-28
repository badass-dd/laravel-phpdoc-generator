<?php

namespace Badass\LazyDocs\Analyzers;

class CacheAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $cacheCalls = $this->nodeFinder->find($methodNode, function (\PhpParser\Node $node) {
            if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
                if ($node->class instanceof \PhpParser\Node\Name) {
                    $className = $node->class->toString();

                    return $className === 'Cache' || $className === 'Illuminate\Support\Facades\Cache';
                }
            }

            if ($node instanceof \PhpParser\Node\Expr\FuncCall) {
                if ($node->name instanceof \PhpParser\Node\Name) {
                    return $node->name->toString() === 'cache';
                }
            }

            return false;
        });

        $operations = [];

        foreach ($cacheCalls as $call) {
            if ($call instanceof \PhpParser\Node\Expr\StaticCall) {
                $methodName = $call->name instanceof \PhpParser\Node\Identifier ? $call->name->toString() : '';
                $operations[] = $methodName;
            } else {
                $operations[] = 'cache';
            }
        }

        return ['cache_operations' => array_unique($operations)];
    }
}
