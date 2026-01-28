<?php

namespace Badass\LazyDocs\Analyzers;

class DatabaseAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $operations = [];

        $staticCalls = $this->nodeFinder->findInstanceOf($methodNode, \PhpParser\Node\Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if ($call->class instanceof \PhpParser\Node\Name) {
                $className = $call->class->toString();

                if ($className === 'DB' || $className === 'Illuminate\Support\Facades\DB') {
                    $methodName = $call->name instanceof \PhpParser\Node\Identifier ? $call->name->toString() : '';

                    if ($methodName === 'transaction') {
                        $operations[] = 'database_transaction';
                    }

                    if (in_array($methodName, ['insert', 'update', 'delete', 'statement'])) {
                        $operations[] = 'database_'.$methodName;
                    }
                }
            }
        }

        return ['database_operations' => $operations];
    }
}
