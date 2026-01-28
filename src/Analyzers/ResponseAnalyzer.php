<?php

namespace Badass\LazyDocs\Analyzers;

use PhpParser\Node;

class ResponseAnalyzer extends BaseAnalyzer
{
    private array $config;

    public function __construct(array $ast, string $controllerClass, string $methodName, array $config)
    {
        parent::__construct($ast, $controllerClass, $methodName);
        $this->config = $config;
    }

    public function analyze(): array
    {
        $methodNode = $this->findMethodNode();
        if (! $methodNode) {
            return [];
        }

        $responses = [];
        $seenStatusCodes = [];

        // Find ALL response()->json() calls in the method, not just returns
        $this->findAllResponseCalls($methodNode, $responses, $seenStatusCodes);

        // Sort responses by status code
        usort($responses, fn ($a, $b) => ($a['status'] ?? 0) <=> ($b['status'] ?? 0));

        return ['responses' => $responses];
    }

    /**
     * Find all response calls in a method, including those inside if/else/try/catch
     */
    private function findAllResponseCalls(Node $node, array &$responses, array &$seenStatusCodes): void
    {
        // Find all method calls that are ->json()
        $methodCalls = $this->nodeFinder->find($node, function (Node $n) {
            if ($n instanceof Node\Expr\MethodCall) {
                $methodName = $n->name instanceof Node\Identifier ? $n->name->toString() : null;

                return $methodName === 'json';
            }

            return false;
        });

        foreach ($methodCalls as $methodCall) {
            // Check if this is response()->json() by verifying the var is a response() call
            if ($this->isResponseCall($methodCall->var)) {
                $response = $this->analyzeJsonMethodCall($methodCall);
                if ($response) {
                    $key = $response['status'].'_'.md5(json_encode($response['content'] ?? ''));
                    if (! isset($seenStatusCodes[$key])) {
                        $responses[] = $response;
                        $seenStatusCodes[$key] = true;
                    }
                }
            }
        }

        // Find Response::json() static calls
        $staticCalls = $this->nodeFinder->find($node, function (Node $n) {
            return $n instanceof Node\Expr\StaticCall &&
                   $n->class instanceof Node\Name &&
                   strtolower($n->class->toString()) === 'response';
        });

        foreach ($staticCalls as $staticCall) {
            $response = $this->analyzeStaticResponseCall($staticCall);
            if ($response) {
                $key = $response['status'].'_'.md5(json_encode($response['content'] ?? ''));
                if (! isset($seenStatusCodes[$key])) {
                    $responses[] = $response;
                    $seenStatusCodes[$key] = true;
                }
            }
        }
    }

    /**
     * Check if a node is a response() function call
     */
    private function isResponseCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\FuncCall &&
            $node->name instanceof Node\Name &&
            $node->name->toString() === 'response') {
            return true;
        }

        // Could be chained: response()->header()->json() - check recursively
        if ($node instanceof Node\Expr\MethodCall) {
            return $this->isResponseCall($node->var);
        }

        return false;
    }

    /**
     * Analyze a ->json() method call
     */
    private function analyzeJsonMethodCall(Node\Expr\MethodCall $call): ?array
    {
        $args = $call->args;

        $dataArg = $args[0]->value ?? null;
        $statusCode = 200;

        if (isset($args[1])) {
            $statusArg = $args[1]->value;
            if ($statusArg instanceof Node\Scalar\LNumber) {
                $statusCode = $statusArg->value;
            }
        }

        $content = $dataArg ? $this->extractResponseContent($dataArg) : null;
        $message = $this->extractMessageFromContent($content);

        return $this->createResponse($statusCode, $content, $message);
    }

    /**
     * Analyze a Response::json() static call
     */
    private function analyzeStaticResponseCall(Node\Expr\StaticCall $call): ?array
    {
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

        if ($methodName !== 'json') {
            return null;
        }

        $args = $call->args;

        if (count($args) < 1) {
            return $this->createResponse(200, []);
        }

        $dataArg = $args[0]->value;
        $statusCode = 200;

        if (isset($args[1])) {
            $statusArg = $args[1]->value;
            if ($statusArg instanceof Node\Scalar\LNumber) {
                $statusCode = $statusArg->value;
            }
        }

        $content = $this->extractResponseContent($dataArg);
        $message = $this->extractMessageFromContent($content);

        return $this->createResponse($statusCode, $content, $message);
    }

    /**
     * Extract the response content from an AST node
     */
    private function extractResponseContent(Node $node): mixed
    {
        if ($node instanceof Node\Expr\Array_) {
            return $this->parseArrayNode($node);
        }

        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            $name = $node->name->toString();

            return $name === 'null' ? null : $name;
        }

        if ($node instanceof Node\Expr\Variable) {
            // Return a placeholder indicating a variable
            $varName = $node->name;

            return is_string($varName) ? ['__variable__' => $varName] : null;
        }

        return null;
    }

    /**
     * Parse an array node to extract its structure
     */
    private function parseArrayNode(Node\Expr\Array_ $arrayNode): array
    {
        $result = [];

        foreach ($arrayNode->items as $item) {
            if ($item instanceof Node\Expr\ArrayItem) {
                $key = $this->extractArrayKey($item->key);
                $value = $this->extractArrayValue($item->value);
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Extract array key from AST node
     */
    private function extractArrayKey(?Node $key): string|int
    {
        if ($key === null) {
            return 0;
        }

        if ($key instanceof Node\Scalar\String_) {
            return $key->value;
        }

        if ($key instanceof Node\Scalar\LNumber) {
            return $key->value;
        }

        if ($key instanceof Node\Identifier) {
            return $key->toString();
        }

        return 0;
    }

    /**
     * Extract array value from AST node
     */
    private function extractArrayValue(Node $value): mixed
    {
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }

        if ($value instanceof Node\Scalar\LNumber) {
            return $value->value;
        }

        if ($value instanceof Node\Scalar\DNumber) {
            return $value->value;
        }

        if ($value instanceof Node\Expr\Array_) {
            return $this->parseArrayNode($value);
        }

        if ($value instanceof Node\Expr\ConstFetch) {
            return $value->name->toString();
        }

        // For method calls like $e->getMessage(), return a placeholder
        if ($value instanceof Node\Expr\MethodCall) {
            $methodName = $value->name instanceof Node\Identifier ? $value->name->toString() : 'unknown';
            if ($methodName === 'getMessage') {
                return '{exception_message}';
            }

            return '{dynamic_value}';
        }

        // For array access like $result['key']
        if ($value instanceof Node\Expr\ArrayDimFetch) {
            $varName = '';
            if ($value->var instanceof Node\Expr\Variable && is_string($value->var->name)) {
                $varName = $value->var->name;
            }
            $keyName = '';
            if ($value->dim instanceof Node\Scalar\String_) {
                $keyName = $value->dim->value;
            }

            return '{$'.$varName.'[\''.$keyName.'\']}';
        }

        // For variable concatenation
        if ($value instanceof Node\Expr\BinaryOp\Concat) {
            return $this->extractConcatValue($value);
        }

        if ($value instanceof Node\Expr\Variable) {
            return '{$'.(is_string($value->name) ? $value->name : 'var').'}';
        }

        return null;
    }

    /**
     * Extract value from string concatenation
     */
    private function extractConcatValue(Node\Expr\BinaryOp\Concat $concat): string
    {
        $left = $this->extractArrayValue($concat->left);
        $right = $this->extractArrayValue($concat->right);

        return ($left ?? '').($right ?? '');
    }

    /**
     * Extract message from response content for documentation
     */
    private function extractMessageFromContent($content): ?string
    {
        if (is_array($content)) {
            return $content['message'] ?? $content['error'] ?? null;
        }

        return null;
    }

    /**
     * Create a response array
     */
    private function createResponse(int $status, mixed $content, ?string $message = null): array
    {
        $response = [
            'status' => $status,
            'content' => $content,
            'content_type' => 'application/json',
        ];

        if ($message) {
            $response['message'] = $message;
        }

        // Categorize response type
        if ($status >= 200 && $status < 300) {
            $response['type'] = 'success';
        } elseif ($status >= 400 && $status < 500) {
            $response['type'] = 'client_error';
        } elseif ($status >= 500) {
            $response['type'] = 'server_error';
        }

        return $response;
    }
}
