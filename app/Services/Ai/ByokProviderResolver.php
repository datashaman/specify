<?php

namespace App\Services\Ai;

use App\Models\AgentRun;
use App\Models\UserAiCredential;
use Laravel\Ai\Ai;
use RuntimeException;

/**
 * Resolves and registers per-run Laravel AI provider config from user BYOK keys.
 */
class ByokProviderResolver
{
    public function forRun(AgentRun $run, string $agentClass): ?ByokProvider
    {
        if (is_callable([$agentClass, 'isFaked']) && $agentClass::isFaked()) {
            return null;
        }

        $user = $run->user;
        if ($user === null) {
            throw new RuntimeException('AI run has no user owner; BYOK credential cannot be resolved.');
        }

        $providers = UserAiCredential::supportedProviders();
        $preferred = $user->ai_provider;
        if (is_string($preferred) && in_array($preferred, $providers, true)) {
            $providers = array_values(array_unique([$preferred, ...$providers]));
        }

        $credential = $user->aiCredentials()
            ->where('enabled', true)
            ->whereIn('provider', $providers)
            ->orderByRaw(
                'case provider '.
                implode(' ', array_map(fn ($provider, $i) => "when ? then {$i}", $providers, array_keys($providers))).
                ' end',
                $providers,
            )
            ->first();

        if ($credential === null) {
            throw new RuntimeException('No enabled Anthropic or OpenAI BYOK credential is configured for this user.');
        }

        $providerName = 'byok-run-'.$run->getKey().'-'.$credential->provider;
        $base = (array) config('ai.providers.'.$credential->provider, ['driver' => $credential->provider]);
        $base['driver'] = $credential->provider;
        $base['key'] = $credential->api_key;

        config(['ai.providers.'.$providerName => $base]);
        Ai::forgetInstance($providerName);

        $model = trim((string) $credential->model);

        return new ByokProvider($providerName, $model !== '' ? $model : null);
    }

    public function release(?ByokProvider $provider): void
    {
        if ($provider === null) {
            return;
        }

        Ai::forgetInstance($provider->provider);

        $providers = (array) config('ai.providers', []);
        unset($providers[$provider->provider]);
        config(['ai.providers' => $providers]);
    }
}
