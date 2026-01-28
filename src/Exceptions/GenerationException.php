<?php

namespace Badass\LazyDocs\Exceptions;

class GenerationException extends \RuntimeException
{
    public static function methodNotAnalyzed(string $method): self
    {
        return new self("Method {$method} has not been analyzed. Call analyze() first.");
    }

    public static function invalidResponseStructure(): self
    {
        return new self('Invalid response structure - cannot generate example.');
    }

    public static function fileWriteError(string $file, string $error): self
    {
        return new self("Failed to write to file {$file}: {$error}");
    }

    public static function scribeCompatibilityError(string $tag): self
    {
        return new self("Failed to generate Scribe-compatible {$tag} tag.");
    }
}
