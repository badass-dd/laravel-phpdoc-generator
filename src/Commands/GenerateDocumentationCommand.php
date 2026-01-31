<?php

namespace Badass\LazyDocs\Commands;

use Badass\LazyDocs\AnalysisEngine;
use Badass\LazyDocs\DocumentationGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Console\Helper\ProgressBar;

class GenerateDocumentationCommand extends Command
{
    protected $signature = 'lazydocs:generate 
                            {controller? : The controller class to document}
                            {--method= : Generate documentation for specific method only}
                            {--all : Generate for all controllers}
                            {--overwrite : Replace existing PHPDoc}
                            {--dry-run : Preview without writing}
                            {--force : Include simple methods}
                            {--output= : Output format (scribe, openapi, markdown)}
                            {--config= : Custom config file}';

    protected $description = 'Generate Scribe-compatible PHPDoc documentation for Laravel controllers';

    private DocumentationGenerator $generator;

    private AnalysisEngine $analyzer;

    private array $config;

    public function handle(): int
    {
        $this->loadConfig();

        // Create instances with the updated config (including --overwrite flags)
        // Don't use app() singletons as they have the original config
        $this->generator = new DocumentationGenerator($this->config);
        $this->analyzer = new AnalysisEngine($this->generator, $this->config);

        if ($this->option('all')) {
            return $this->generateForAllControllers();
        }

        if ($this->argument('controller')) {
            return $this->generateForController($this->argument('controller'));
        }

        $this->error('Please specify a controller or use --all');

        return 1;
    }

    private function loadConfig(): void
    {
        $configFile = $this->option('config');

        if ($configFile && File::exists($configFile)) {
            $this->config = require $configFile;
        } else {
            $this->config = config('lazydocs', []);
        }

        if ($this->option('force')) {
            $this->config['include_simple_methods'] = true;
        }

        if ($this->option('overwrite')) {
            $this->config['output']['preserve_existing'] = false;
            $this->config['output']['merge_strategy'] = 'overwrite';
        }

        if ($this->option('output')) {
            $this->config['output']['format'] = $this->option('output');
        }
    }

    private function generateForAllControllers(): int
    {
        $controllers = $this->findControllers();

        if (empty($controllers)) {
            $this->error('No controllers found in configured paths.');

            return 1;
        }

        $this->info('Found '.count($controllers).' controllers');

        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        $progressBar = new ProgressBar($this->output, count($controllers));
        $progressBar->start();

        foreach ($controllers as $controller) {
            try {
                $result = $this->processSingleController($controller, true);

                if ($result === 0) {
                    $successCount++;
                } elseif ($result === 2) {
                    $skippedCount++;
                } else {
                    $errorCount++;
                }

                $progressBar->advance();
            } catch (\Exception $e) {
                $this->error("Error processing {$controller}: ".$e->getMessage());
                $errorCount++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Generation complete!');
        $this->info("✅ Successfully documented: {$successCount} controllers");
        $this->info("⚠️  Skipped (no methods): {$skippedCount} controllers");
        $this->info("❌ Errors: {$errorCount} controllers");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No files were modified. Use --overwrite to write changes.');
        }

        return $errorCount > 0 ? 1 : 0;
    }

    private function generateForController(string $controller, bool $batchMode = false): int
    {
        // Resolve controller name if not a fully qualified class name
        $controller = $this->resolveControllerClass($controller);

        return $this->processSingleController($controller, $batchMode);
    }

    /**
     * Resolve a controller class name from various input formats.
     * Supports:
     *   - Full class name: App\Http\Controllers\Api\UserController
     *   - Short name: UserController
     *   - Partial path: Api\UserController
     */
    private function resolveControllerClass(string $controller): string
    {
        // If it's already a valid class, return it
        if (class_exists($controller)) {
            return $controller;
        }

        // Common namespaces to try
        $namespaces = [
            'App\\Http\\Controllers\\Api\\',
            'App\\Http\\Controllers\\',
        ];

        // If it doesn't contain namespace separator, try to find it
        if (! str_contains($controller, '\\')) {
            foreach ($namespaces as $namespace) {
                $fullClass = $namespace.$controller;
                if (class_exists($fullClass)) {
                    return $fullClass;
                }
            }
        }

        // Try prepending App\Http\Controllers if it starts with Api\
        if (str_starts_with($controller, 'Api\\')) {
            $fullClass = 'App\\Http\\Controllers\\'.$controller;
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        // Return original - will fail later with a clear error
        return $controller;
    }

    private function processSingleController(string $controller, bool $batchMode = false): int
    {
        try {
            if (! $batchMode) {
                $this->info("Analyzing controller: {$controller}");
            }

            if (! class_exists($controller)) {
                if (! $batchMode) {
                    $this->error("Controller class {$controller} not found.");
                }

                return 1;
            }

            $reflection = new ReflectionClass($controller);

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                if (! $batchMode) {
                    $this->warn("Skipping abstract class/interface/trait: {$controller}");
                }

                return 2;
            }

            $analysis = $this->analyzer->analyzeController($controller);

            if (empty($analysis)) {
                if (! $batchMode) {
                    $this->warn("No methods to document in {$controller}");
                }

                return 2;
            }

            if ($this->option('method')) {
                $methodName = $this->option('method');
                if (! isset($analysis[$methodName])) {
                    if (! $batchMode) {
                        $this->error("Method {$methodName} not found or not analyzable in {$controller}");
                    }

                    return 1;
                }
                $analysis = [$methodName => $analysis[$methodName]];
            }

            $results = [];
            foreach ($analysis as $methodName => $methodAnalysis) {
                try {
                    // Pass the enhanced analysis from AnalysisEngine to the generator
                    $docBlock = $this->generator->generateForMethodWithAnalysis($methodName, $methodAnalysis);
                    $results[$methodName] = $docBlock;
                } catch (\Exception $e) {
                    if (! $batchMode) {
                        $this->error("Error generating docs for {$methodName}: ".$e->getMessage());
                    }
                }
            }

            if (empty($results)) {
                if (! $batchMode) {
                    $this->warn("No documentation generated for {$controller}");
                }

                return 2;
            }

            if ($this->option('dry-run')) {
                $this->displayPreview($controller, $results, $batchMode);
            } else {
                $this->writeToController($controller, $results);

                if (! $batchMode) {
                    $this->info("✅ Successfully generated documentation for {$controller}");
                }
            }

            return 0;

        } catch (\Exception $e) {
            if (! $batchMode) {
                $this->error("Error processing {$controller}: ".$e->getMessage());
            }

            return 1;
        }
    }

    private function findControllers(): array
    {
        $paths = $this->config['controller_paths'] ?? [
            app_path('Http/Controllers'),
            app_path('Http/Controllers/Api'),
        ];

        $controllers = [];

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            $files = File::allFiles($path);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = File::get($file->getPathname());

                if (preg_match('/\bclass\s+(\w+Controller)\b/', $content, $matches)) {
                    $className = $matches[1];

                    $namespace = '';
                    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
                        $namespace = $nsMatches[1];
                    }

                    $fullClassName = $namespace ? $namespace.'\\'.$className : $className;

                    if (class_exists($fullClassName)) {
                        $controllers[] = $fullClassName;
                    }
                }
            }
        }

        return array_unique($controllers);
    }

    private function displayPreview(string $controller, array $docBlocks, bool $batchMode = false): void
    {
        if ($batchMode) {
            return;
        }

        $this->info("\n".str_repeat('=', 80));
        $this->info("Preview for: {$controller}");
        $this->info(str_repeat('=', 80));

        foreach ($docBlocks as $method => $docBlock) {
            $this->info("\n📝 Method: {$method}");
            $this->info(str_repeat('-', 40));
            $this->line($docBlock);
        }

        $this->info("\n".str_repeat('=', 80));
        $this->info('Total methods documented: '.count($docBlocks));
        $this->info(str_repeat('=', 80));
    }

    private function writeToController(string $controller, array $docBlocks): void
    {
        $reflection = new ReflectionClass($controller);
        $filePath = $reflection->getFileName();

        if (! $filePath) {
            throw new \RuntimeException("Cannot locate source file for {$controller}");
        }

        $content = File::get($filePath);
        $lines = explode("\n", $content);

        foreach ($docBlocks as $methodName => $docBlock) {
            // Find the method line
            $methodLineIndex = null;
            foreach ($lines as $index => $line) {
                // Match function declaration - handles public/protected/private and type hints
                if (preg_match('/^\s*(public|protected|private)?\s*function\s+'.preg_quote($methodName, '/').'\s*\(/i', $line)) {
                    $methodLineIndex = $index;
                    break;
                }
            }

            if ($methodLineIndex === null) {
                continue;
            }

            // Find existing docblocks and attributes above the method
            $result = $this->analyzeAboveMethod($lines, $methodLineIndex);

            if ($result['docblocks_start'] !== null) {
                // Remove all docblocks and attributes from docblocks_start to method
                $removeCount = $methodLineIndex - $result['docblocks_start'];
                array_splice($lines, $result['docblocks_start'], $removeCount);
                $methodLineIndex = $result['docblocks_start'];
            }

            // Get indentation from the method line
            preg_match('/^(\s*)/', $lines[$methodLineIndex], $indentMatch);
            $indent = $indentMatch[1] ?? '    ';

            // Format docblock with proper indentation
            $docLines = explode("\n", $docBlock);
            $formattedDocBlock = [];
            foreach ($docLines as $docLine) {
                $formattedDocBlock[] = $indent.$docLine;
            }

            // Add preserved attributes after the docblock
            foreach ($result['attributes'] as $attr) {
                $formattedDocBlock[] = $indent.$attr;
            }

            // Insert the new docblock (and attributes) before the method
            array_splice($lines, $methodLineIndex, 0, $formattedDocBlock);
        }

        $newContent = implode("\n", $lines);

        // Clean up any duplicate blank lines
        $newContent = preg_replace("/\n{3,}/", "\n\n", $newContent);

        File::put($filePath, $newContent);

        if ($this->config['output']['format_output'] ?? true) {
            $this->formatFile($filePath);
        }
    }

    private function findExistingDocEnd(array $lines, int $methodLineIndex): int
    {
        for ($i = $methodLineIndex - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);

            if (str_starts_with($trimmed, '/**')) {
                for ($j = $i; $j < count($lines); $j++) {
                    if (str_contains($lines[$j], '*/')) {
                        return $j;
                    }
                }

                return $i;
            }

            if (! empty($trimmed) && ! str_starts_with($trimmed, '*') && $trimmed !== '{') {
                break;
            }
        }

        return $methodLineIndex;
    }

    /**
     * Analyze lines above a method to find docblocks and attributes.
     * Returns the start index of docblocks and a list of preserved attributes.
     *
     * @return array{docblocks_start: ?int, attributes: string[]}
     */
    private function analyzeAboveMethod(array $lines, int $methodLineIndex): array
    {
        $docblocksStart = null;
        $attributes = [];
        $inDocblock = false;

        for ($i = $methodLineIndex - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '') {
                continue;
            }

            // Docblock end (scanning upwards, so this is where we enter a docblock)
            if (str_ends_with($trimmed, '*/')) {
                $inDocblock = true;
                $docblocksStart = $i;

                continue;
            }

            // Inside a docblock
            if ($inDocblock) {
                if (str_starts_with($trimmed, '/**')) {
                    $docblocksStart = $i;
                    $inDocblock = false;

                    continue;
                }
                if (str_starts_with($trimmed, '*')) {
                    $docblocksStart = $i;

                    continue;
                }
            }

            // Docblock start (when not already in docblock)
            if (str_starts_with($trimmed, '/**')) {
                $docblocksStart = $i;

                continue;
            }

            // Docblock content
            if (str_starts_with($trimmed, '*') || str_ends_with($trimmed, '*/')) {
                continue;
            }

            // PHP 8 Attributes - collect them (without indentation, we'll re-add it later)
            if (str_starts_with($trimmed, '#[')) {
                array_unshift($attributes, $trimmed);
                if ($docblocksStart === null) {
                    $docblocksStart = $i;
                } else {
                    $docblocksStart = $i;
                }

                continue;
            }

            // Any other non-doc line stops the search
            break;
        }

        return [
            'docblocks_start' => $docblocksStart,
            'attributes' => $attributes,
        ];
    }

    private function findExistingDocStart(array $lines, int $methodLineIndex): ?int
    {
        $start = null;
        for ($i = $methodLineIndex - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '') {
                // continue to allow blank lines between docblocks
                continue;
            }

            if (str_starts_with($trimmed, '/**')) {
                $start = $i;

                // continue upwards to capture stacked docblocks
                continue;
            }

            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '*/')) {
                // part of a docblock - keep scanning upwards
                continue;
            }

            // PHP 8 Attributes - skip them and continue looking for docblocks above
            if (str_starts_with($trimmed, '#[')) {
                continue;
            }

            // any other non-doc line stops the search
            break;
        }

        return $start;
    }

    private function formatFile(string $filePath): void
    {
        if (! function_exists('exec')) {
            return;
        }

        $formatters = [
            'php-cs-fixer' => ['php-cs-fixer', 'fix', '--using-cache=no', '--rules=@PSR12'],
            'laravel-pint' => ['php', base_path('vendor/bin/pint')],
        ];

        foreach ($formatters as $formatter => $command) {
            try {
                if ($this->isCommandAvailable($command[0])) {
                    $fullCommand = implode(' ', array_merge($command, [$filePath]));
                    exec($fullCommand, $output, $returnCode);

                    if ($returnCode === 0) {
                        $this->debug("Formatted with {$formatter}: {$filePath}");
                        break;
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $content = File::get($filePath);
        $content = preg_replace('/\r\n|\r/', "\n", $content);
        File::put($filePath, $content);
    }

    private function isCommandAvailable(string $command): bool
    {
        if ($command === 'php-cs-fixer') {
            return File::exists(base_path('vendor/friendsofphp/php-cs-fixer/php-cs-fixer'));
        }

        if ($command === 'php') {
            return function_exists('exec');
        }

        return false;
    }

    private function debug(string $message): void
    {
        if ($this->config['debug'] ?? false) {
            $this->line($message);
        }
    }
}
