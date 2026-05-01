<?php

namespace App\Services\Executors;

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

    public function make(string $name): Executor
    {
        $driver = $this->driverConfig($name);
        $class = (string) ($driver['class'] ?? '');

        if ($class === '' || ! is_subclass_of($class, Executor::class)) {
            throw new InvalidArgumentException("Driver [{$name}] does not declare a valid Executor class.");
        }

        return match ($class) {
            CliExecutor::class => new CliExecutor(
                command: array_values((array) ($driver['command'] ?? [])),
                timeout: (int) ($driver['timeout'] ?? 1800),
            ),
            default => $this->container->make($class),
        };
    }

    /**
     * @return list<string>
     */
    public function raceDrivers(): array
    {
        $race = (array) config('specify.executor.race', []);

        return array_values(array_filter(array_map('strval', $race), fn ($name) => $name !== ''));
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
}
