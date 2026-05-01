<?php

namespace App\Providers;

use App\Services\Executors\CliExecutor;
use App\Services\Executors\Executor;
use App\Services\Executors\FakeExecutor;
use App\Services\Executors\LaravelAiExecutor;
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
        $this->app->singleton(WorkspaceRunner::class, fn () => WorkspaceRunner::fromConfig());

        $this->app->bind(Executor::class, function ($app) {
            $driver = config('specify.executor.driver', 'laravel-ai');

            return match ($driver) {
                'laravel-ai' => new LaravelAiExecutor,
                'cli' => new CliExecutor(
                    command: (array) config('specify.executor.cli.command'),
                    timeout: (int) config('specify.executor.cli.timeout', 1800),
                ),
                'fake' => new FakeExecutor,
                default => throw new InvalidArgumentException("Unknown executor driver [{$driver}]."),
            };
        });
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
