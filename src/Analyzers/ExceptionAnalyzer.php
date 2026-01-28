<?php

namespace Badass\LazyDocs\Analyzers;

use PhpParser\NodeFinder;

class ExceptionAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $exceptions = [];

        $throwFinder = new NodeFinder;
        $throwNodes = $throwFinder->findInstanceOf($methodNode, \PhpParser\Node\Stmt\Throw_::class);

        foreach ($throwNodes as $throwNode) {
            if ($throwNode->expr instanceof \PhpParser\Node\Expr\New_) {
                $exceptionClass = $throwNode->expr->class->toString();
                $exceptions[] = [
                    'exception' => $exceptionClass,
                    'status_code' => $this->mapExceptionToStatusCode($exceptionClass),
                ];
            }
        }

        return ['exceptions' => $exceptions];
    }

    private function mapExceptionToStatusCode(string $exception): int
    {
        $mappings = [
            'Illuminate\Auth\AuthenticationException' => 401,
            'Illuminate\Auth\Access\AuthorizationException' => 403,
            'Illuminate\Database\Eloquent\ModelNotFoundException' => 404,
            'Illuminate\Validation\ValidationException' => 422,
            'Symfony\Component\HttpKernel\Exception\NotFoundHttpException' => 404,
            'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException' => 403,
            'Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException' => 429,
        ];

        return $mappings[$exception] ?? 400;
    }
}
