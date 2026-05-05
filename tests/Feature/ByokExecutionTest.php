<?php

use App\Ai\Agents\TasksGenerator;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\User;
use App\Models\UserAiCredential;
use App\Services\Ai\ByokProviderResolver;
use App\Services\ExecutionService;
use App\Services\Executors\ExecutorFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('user AI credentials are encrypted and hidden', function () {
    $credential = UserAiCredential::factory()->create([
        'api_key' => 'sk-ant-test-secret',
    ]);

    $raw = DB::table('user_ai_credentials')->whereKey($credential->getKey())->value('api_key');

    expect($raw)->not->toBe('sk-ant-test-secret')
        ->and($credential->fresh()->api_key)->toBe('sk-ant-test-secret')
        ->and($credential->fresh()->toArray())->not->toHaveKey('api_key');
});

test('AI settings page saves default provider credentials', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('anthropic_api_key', 'sk-ant-user-key')
        ->set('anthropic_model', 'claude-sonnet-4-6')
        ->set('anthropic_enabled', true)
        ->call('saveProvider', 'anthropic')
        ->assertHasNoErrors();

    $credential = $user->aiCredentials()->where('provider', 'anthropic')->sole();

    expect($credential->api_key)->toBe('sk-ant-user-key')
        ->and($credential->model)->toBe('claude-sonnet-4-6')
        ->and($credential->enabled)->toBeTrue()
        ->and($user->fresh()->ai_provider)->toBe('anthropic');
});

test('AI settings page removes a provider key', function () {
    $user = User::factory()->create(['ai_provider' => 'anthropic']);
    UserAiCredential::factory()->for($user)->create(['provider' => 'anthropic']);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->call('removeProvider', 'anthropic')
        ->assertHasNoErrors();

    expect($user->aiCredentials()->count())->toBe(0)
        ->and($user->fresh()->ai_provider)->toBeNull();
});

test('BYOK resolver registers a per-run provider config from the run owner', function () {
    $user = User::factory()->create(['ai_provider' => 'openai']);
    UserAiCredential::factory()->for($user)->create([
        'provider' => 'openai',
        'api_key' => 'sk-openai-user-key',
        'model' => 'gpt-5.4',
    ]);
    $run = AgentRun::factory()->for($user)->create();

    $provider = app(ByokProviderResolver::class)->forRun($run, TasksGenerator::class);

    expect($provider->provider)->toBe('byok-run-'.$run->getKey().'-openai')
        ->and($provider->model)->toBe('gpt-5.4')
        ->and(config('ai.providers.'.$provider->provider.'.driver'))->toBe('openai')
        ->and(config('ai.providers.'.$provider->provider.'.key'))->toBe('sk-openai-user-key');
});

test('task generation fails before provider calls when the run owner has no BYOK key', function () {
    config(['queue.default' => 'sync']);

    $user = User::factory()->create();
    $story = Story::factory()->create();

    $this->actingAs($user);

    expect(fn () => app(ExecutionService::class)->dispatchTaskGeneration($story))
        ->toThrow(RuntimeException::class, 'No enabled Anthropic or OpenAI BYOK credential');

    $run = AgentRun::query()->where('runnable_id', $story->getKey())->sole();
    expect($run->user_id)->toBe($user->getKey())
        ->and($run->status)->toBe(AgentRunStatus::Failed);
});

test('task generation persists the triggering user on the run', function () {
    config(['queue.default' => 'sync']);

    $user = User::factory()->create();
    $story = Story::factory()->create();
    TasksGenerator::fake(fn () => [
        'summary' => 'one task plan',
        'tasks' => [[
            'name' => 'Task',
            'description' => 'Task description',
            'position' => 1,
            'subtasks' => [[
                'name' => 'Subtask',
                'description' => 'Subtask description',
                'position' => 1,
            ]],
        ]],
    ]);

    $this->actingAs($user);

    $run = app(ExecutionService::class)->dispatchTaskGeneration($story);

    expect($run->fresh()->user_id)->toBe($user->getKey());
});

test('hosted runtime blocks local-only executor drivers', function () {
    config(['specify.runtime.environment' => 'hosted']);

    expect(fn () => app(ExecutorFactory::class)->make('cli'))
        ->toThrow(InvalidArgumentException::class, 'local-only');
});

test('hosted runtime fails race config containing local-only drivers', function () {
    config([
        'specify.runtime.environment' => 'hosted',
        'specify.executor.race' => ['laravel-ai', 'cli-codex'],
    ]);

    expect(fn () => app(ExecutorFactory::class)->raceDrivers())
        ->toThrow(InvalidArgumentException::class, 'local-only');
});
