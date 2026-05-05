<?php

namespace App\Services\Executors;

use App\Models\AgentRun;
use App\Services\Ai\ByokProviderResolver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves an Executor by driver name (e.g. `laravel-ai`, `cli-claude`, `fake`).
 *
 * The driver registry lives in `config('specify.executor.drivers')` as a map
 * from name to driver definition. Each definition has at least a `class` key
 * naming the Executor implementation; CLI-style drivers add `command` and
 * `timeout`. The single `default` driver name (used when nothing else asks)
 * lives in `config('specify.executor.default')`. The optional race list
 * (`config('specify.executor.race')`) uses driver names from the same map.
 *
 * One source of truth: every place that needs an Executor — the global
 * binding, `ExecuteSubtaskJob`, tests — calls `make($name)` with a name
 * that is guaranteed to resolve.
 */
class ExecutorFactory
{
    public function __construct(public Container $container) {}

    public function make(string $name, ?AgentRun $run = null): Executor
    {
        $driver = $this->driverConfig($name);
        $this->assertRunnableInCurrentRuntime($name, $driver);
        $class = (string) ($driver['class'] ?? '');

        if ($class === '' || ! is_subclass_of($class, Executor::class)) {
            throw new InvalidArgumentException("Driver [{$name}] does not declare a valid Executor class.");
        }

        return match ($class) {
            CliExecutor::class => new CliExecutor(
                command: array_values((array) ($driver['command'] ?? [])),
                timeout: (int) ($driver['timeout'] ?? 1800),
            ),
            LaravelAiExecutor::class => new LaravelAiExecutor(
                byok: $this->container->make(ByokProviderResolver::class),
                run: $run,
            ),
            default => $this->container->make($class),
        };
    }

    /**
     * Race driver names from config, validated against the registry. A typo
     * in `SPECIFY_EXECUTOR_RACE` fails fast here with a clear message rather
     * than dispatching AgentRuns that crash later inside the queue worker.
     *
     * @return list<string>
     */
    public function raceDrivers(): array
    {
        $race = (array) config('specify.executor.race', []);
        $names = array_values(array_filter(array_map('strval', $race), fn ($name) => $name !== ''));

        $drivers = (array) config('specify.executor.drivers', []);
        foreach ($names as $name) {
            if (! isset($drivers[$name])) {
                throw new InvalidArgumentException(
                    "Race driver [{$name}] is not registered in specify.executor.drivers."
                );
            }
            $this->assertRunnableInCurrentRuntime($name, (array) $drivers[$name]);
        }

        return $names;
    }

    public function defaultDriver(): string
    {
        return (string) config('specify.executor.default', 'laravel-ai');
    }

    /**
     * @return array<string, mixed>
     */
    private function driverConfig(string $name): array
    {
        $drivers = (array) config('specify.executor.drivers', []);
        if (! isset($drivers[$name]) || ! is_array($drivers[$name])) {
            throw new InvalidArgumentException("Unknown executor driver [{$name}].");
        }

        return $drivers[$name];
    }

    /**
     * @param  array<string, mixed>  $driver
     */
    private function assertRunnableInCurrentRuntime(string $name, array $driver): void
    {
        $runtime = (string) config('specify.runtime.environment', 'local');
        $environment = (string) ($driver['environment'] ?? 'local');

        if ($runtime === 'hosted' && $environment !== 'remote' && ! $this->isExplicitlyRemoteEnabled($name)) {
            throw new InvalidArgumentException("Executor driver [{$name}] is local-only and cannot run in hosted runtime unless it is explicitly listed in SPECIFY_REMOTE_EXECUTORS for a remote-safe worker deployment.");
        }

        if (app()->isProduction() && (($driver['class'] ?? null) === FakeExecutor::class)) {
            throw new InvalidArgumentException("Executor driver [{$name}] is not available in production.");
        }
    }

    private function isExplicitlyRemoteEnabled(string $name): bool
    {
        $names = (array) config('specify.runtime.remote_executors', []);

        return in_array($name, array_map('strval', $names), true);
    }
}
