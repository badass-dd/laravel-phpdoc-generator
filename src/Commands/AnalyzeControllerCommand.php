<?php

namespace Badass\LazyDocs\Commands;

use Badass\LazyDocs\AnalysisEngine;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class AnalyzeControllerCommand extends Command
{
    protected $signature = 'lazydocs:analyze 
                            {controller : The controller to analyze}
                            {--json : Output as JSON}
                            {--detailed : Show detailed analysis}';

    protected $description = 'Analyze a controller without generating documentation';

    public function handle(): int
    {
        $controller = $this->argument('controller');

        try {
            /** @var AnalysisEngine $analyzer */
            $analyzer = app(AnalysisEngine::class);
            $analysis = $analyzer->analyzeController($controller);

            if ($this->option('json')) {
                $this->line(json_encode($analysis, JSON_PRETTY_PRINT));

                return 0;
            }

            $this->displayAnalysis($controller, $analysis);

            return 0;

        } catch (\Exception $e) {
            $this->error('Analysis failed: '.$e->getMessage());

            return 1;
        }
    }

    private function displayAnalysis(string $controller, array $analysis): void
    {
        $this->info("📊 Analysis for: {$controller}");
        $this->line(str_repeat('=', 60));

        foreach ($analysis as $methodName => $methodAnalysis) {
            $this->info("\n📝 Method: {$methodName}");

            if ($this->option('detailed')) {
                $this->displayDetailedAnalysis($methodAnalysis);
            } else {
                $this->displaySummary($methodAnalysis);
            }
        }
    }

    private function displaySummary(array $analysis): void
    {
        $table = new Table($this->output);
        $table->setHeaders(['Metric', 'Value']);

        $table->addRow(['Complexity', $analysis['complexity']['cyclomatic'] ?? 'N/A']);
        $table->addRow(['Parameters', count($analysis['parameters'] ?? [])]);
        $table->addRow(['Responses', count($analysis['responses'] ?? [])]);
        $table->addRow(['Exceptions', count($analysis['exceptions'] ?? [])]);
        $table->addRow(['DB Operations', count($analysis['operations']['database'] ?? [])]);

        $table->render();
    }

    private function displayDetailedAnalysis(array $analysis): void
    {
        // Implement detailed analysis display if needed
        $this->info('Detailed analysis not yet implemented.');
    }
}
