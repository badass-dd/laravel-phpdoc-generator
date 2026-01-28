<?php

namespace Badass\LazyDocs;

use Illuminate\Support\ServiceProvider;

class LazyDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lazydocs.php', 'lazydocs');
        $this->app->singleton(DocumentationGenerator::class, function ($app) {
            return new DocumentationGenerator($app['config']->get('lazydocs', []));
        });

        $this->app->singleton(AnalysisEngine::class, function ($app) {
            return new AnalysisEngine(
                $app->make(DocumentationGenerator::class),
                $app['config']->get('lazydocs', [])
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lazydocs.php' => config_path('lazydocs.php'),
            ], 'lazydocs-config');

            $this->commands([
                Commands\GenerateDocumentationCommand::class,
                Commands\AnalyzeControllerCommand::class,
            ]);
        }
    }
}
