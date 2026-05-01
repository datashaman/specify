<?php

namespace App\Providers;

use App\Services\Context\ContextBuilder;
use App\Services\Context\NullContextBuilder;
use App\Services\Context\RecencyContextBuilder;
use App\Services\Executors\Executor;
use App\Services\Executors\ExecutorFactory;
use App\Services\Prompts\PromptLoader;
use App\Services\WorkspaceRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

/**
 * Wires the `Executor` interface to the configured driver and registers other
 * application-level singletons (WorkspaceRunner, immutable dates, default
 * password rules).
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: the loader caches in-memory, but we want
        // edits to prompts/*.md to be picked up between requests / queue
        // jobs in long-lived workers (Octane, queue:work) without a
        // process restart. `scoped` ties the cache lifetime to a single
        // request/job.
        $this->app->scoped(PromptLoader::class, fn () => new PromptLoader(base_path('prompts')));

        $this->app->singleton(WorkspaceRunner::class, fn () => WorkspaceRunner::fromConfig());

        $this->app->bind(ContextBuilder::class, function () {
            $driver = config('specify.context.builder', 'recency');

            return match ($driver) {
                'recency' => new RecencyContextBuilder(
                    window: (string) config('specify.context.recency.window', '30.days'),
                    maxFiles: (int) config('specify.context.recency.max_files', 10),
                ),
                'null', null, '' => new NullContextBuilder,
                default => throw new InvalidArgumentException("Unknown context builder [{$driver}]."),
            };
        });

        $this->app->singleton(ExecutorFactory::class, fn ($app) => new ExecutorFactory($app));

        $this->app->bind(
            Executor::class,
            fn ($app) => $app->make(ExecutorFactory::class)->make(
                $app->make(ExecutorFactory::class)->defaultDriver(),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
