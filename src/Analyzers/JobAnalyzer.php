<?php

namespace Badass\LazyDocs\Analyzers;

class JobAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $jobCalls = $this->nodeFinder->find($methodNode, function (\PhpParser\Node $node) {
            if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
                if ($node->class instanceof \PhpParser\Node\Name) {
                    $className = $node->class->toString();
                    // Check if it's a job class (ends with Job or implements ShouldQueue)
                    if (str_ends_with($className, 'Job')) {
                        return true;
                    }
                }
            }

            if ($node instanceof \PhpParser\Node\Expr\MethodCall) {
                $methodName = $node->name instanceof \PhpParser\Node\Identifier ? $node->name->toString() : '';

                return in_array($methodName, ['dispatch', 'dispatchSync', 'dispatchNow']);
            }

            return false;
        });

        $jobs = [];

        foreach ($jobCalls as $call) {
            if ($call instanceof \PhpParser\Node\Expr\StaticCall) {
                $className = $call->class->toString();
                $jobs[] = $className;
            } elseif ($call instanceof \PhpParser\Node\Expr\MethodCall) {
                $jobs[] = 'job_dispatch';
            }
        }

        return ['job_operations' => array_unique($jobs)];
    }
}
