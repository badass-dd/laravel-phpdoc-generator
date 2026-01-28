<?php

namespace Badass\LazyDocs\Analyzers;

class AuthorizationAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $authorizationCalls = $this->nodeFinder->find($methodNode, function (\PhpParser\Node $node) {
            if ($node instanceof \PhpParser\Node\Expr\MethodCall) {
                $methodName = $node->name instanceof \PhpParser\Node\Identifier ? $node->name->toString() : '';

                return in_array($methodName, ['authorize', 'can', 'cannot']);
            }

            return false;
        });

        $authorization = [
            'required' => ! empty($authorizationCalls),
            'calls' => [],
        ];

        foreach ($authorizationCalls as $call) {
            $methodName = $call->name instanceof \PhpParser\Node\Identifier ? $call->name->toString() : '';
            $authorization['calls'][] = $methodName;
        }

        return ['authorization' => $authorization];
    }
}
