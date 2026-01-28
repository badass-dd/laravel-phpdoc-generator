<?php

namespace Badass\LazyDocs\Exceptions;

class AnalysisException extends \RuntimeException
{
    public static function controllerNotFound(string $controller): self
    {
        return new self("Controller class {$controller} not found or is not instantiable.");
    }

    public static function methodNotFound(string $controller, string $method): self
    {
        return new self("Method {$method} not found in {$controller}.");
    }

    public static function parseError(string $file, string $error): self
    {
        return new self("Failed to parse {$file}: {$error}");
    }

    public static function invalidConfiguration(string $key): self
    {
        return new self("Invalid configuration value for key: {$key}");
    }

    public static function astError(string $error): self
    {
        return new self("AST parsing error: {$error}");
    }
}
