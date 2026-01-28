<?php

namespace Badass\LazyDocs\Analyzers;

use Illuminate\Support\Facades\Route;
use PhpParser\Node;

class MiddlewareAnalyzer extends BaseAnalyzer
{
    public function analyze(): array
    {
        $middleware = [
            'controller_middleware' => $this->analyzeControllerMiddleware(),
            'route_middleware' => $this->analyzeRouteMiddleware(),
            'requires_auth' => false,
            'auth_guard' => null,
        ];

        // Determine if authentication is required
        $allMiddleware = array_merge(
            $middleware['controller_middleware'],
            $middleware['route_middleware']
        );

        foreach ($allMiddleware as $mw) {
            if ($this->isAuthMiddleware($mw)) {
                $middleware['requires_auth'] = true;
                $middleware['auth_guard'] = $this->extractAuthGuard($mw);
                break;
            }
        }

        return ['middleware' => $middleware];
    }

    /**
     * Analyze middleware defined in the controller's constructor
     */
    private function analyzeControllerMiddleware(): array
    {
        $middleware = [];

        // Find the constructor
        $constructor = $this->nodeFinder->findFirst($this->ast, function (Node $node) {
            return $node instanceof Node\Stmt\ClassMethod &&
                   $node->name->toString() === '__construct';
        });

        if (! $constructor) {
            return $middleware;
        }

        // Find $this->middleware() calls in constructor
        $middlewareCalls = $this->nodeFinder->find($constructor, function (Node $node) {
            if ($node instanceof Node\Expr\MethodCall) {
                $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

                return $methodName === 'middleware';
            }

            return false;
        });

        foreach ($middlewareCalls as $call) {
            $mwInfo = $this->extractMiddlewareInfo($call);
            if ($mwInfo) {
                $middleware[] = $mwInfo;
            }
        }

        return $middleware;
    }

    /**
     * Analyze middleware from the route definition
     */
    private function analyzeRouteMiddleware(): array
    {
        $middleware = [];

        try {
            // Try to find the route for this controller method
            $routes = Route::getRoutes();

            foreach ($routes as $route) {
                $action = $route->getAction();

                if (isset($action['controller'])) {
                    $controllerAction = $action['controller'];

                    // Check if this route points to our controller method
                    if (str_contains($controllerAction, $this->controllerClass.'@'.$this->methodName)) {
                        $routeMiddleware = $route->middleware();

                        foreach ($routeMiddleware as $mw) {
                            $middleware[] = [
                                'name' => is_string($mw) ? $mw : get_class($mw),
                                'only' => null,
                                'except' => null,
                            ];
                        }
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Routes may not be available during analysis
        }

        return $middleware;
    }

    /**
     * Extract middleware information from a $this->middleware() call
     */
    private function extractMiddlewareInfo(Node\Expr\MethodCall $call): ?array
    {
        $args = $call->args;

        if (empty($args)) {
            return null;
        }

        $middlewareName = $this->extractNodeValue($args[0]->value);

        if (! $middlewareName) {
            return null;
        }

        $info = [
            'name' => $middlewareName,
            'only' => null,
            'except' => null,
        ];

        // Check for chained ->only() or ->except() calls
        $parent = $call->getAttribute('parent');

        // Look for chained method calls
        if ($call->var instanceof Node\Expr\MethodCall) {
            $chainedCall = $call->var;
            $chainedMethod = $chainedCall->name instanceof Node\Identifier ? $chainedCall->name->toString() : '';

            if ($chainedMethod === 'only' && ! empty($chainedCall->args)) {
                $info['only'] = $this->extractArrayValues($chainedCall->args[0]->value);
            } elseif ($chainedMethod === 'except' && ! empty($chainedCall->args)) {
                $info['except'] = $this->extractArrayValues($chainedCall->args[0]->value);
            }
        }

        return $info;
    }

    /**
     * Check if a middleware is an authentication middleware
     */
    private function isAuthMiddleware($middleware): bool
    {
        $name = is_array($middleware) ? ($middleware['name'] ?? '') : $middleware;

        // Check if this middleware applies to our method
        if (is_array($middleware)) {
            $only = $middleware['only'] ?? null;
            $except = $middleware['except'] ?? null;

            if ($only && ! in_array($this->methodName, $only)) {
                return false;
            }

            if ($except && in_array($this->methodName, $except)) {
                return false;
            }
        }

        $authMiddleware = [
            'auth',
            'auth:',
            'auth:api',
            'auth:sanctum',
            'auth:web',
            'Authenticate',
            'authenticated',
        ];

        foreach ($authMiddleware as $auth) {
            if (str_starts_with($name, $auth) || $name === $auth) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the auth guard from middleware name (e.g., 'auth:api' -> 'api')
     */
    private function extractAuthGuard($middleware): ?string
    {
        $name = is_array($middleware) ? ($middleware['name'] ?? '') : $middleware;

        if (str_contains($name, ':')) {
            $parts = explode(':', $name);

            return $parts[1] ?? null;
        }

        return null;
    }

    /**
     * Extract value from AST node
     */
    private function extractNodeValue(Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            return $node->class->toString().'::'.$node->name->toString();
        }

        return null;
    }

    /**
     * Extract array values from an array node
     */
    private function extractArrayValues(Node $node): array
    {
        $values = [];

        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item instanceof Node\Expr\ArrayItem && $item->value instanceof Node\Scalar\String_) {
                    $values[] = $item->value->value;
                }
            }
        } elseif ($node instanceof Node\Scalar\String_) {
            $values[] = $node->value;
        }

        return $values;
    }
}
