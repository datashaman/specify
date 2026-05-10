<?php

namespace App\Services\Ai;

use App\Ai\Agents\ContextSummariser;
use App\Enums\ContextItemType;
use App\Models\ContextItem;
use App\Models\User;
use App\Models\UserAiCredential;
use Laravel\Ai\Ai;
use Throwable;

/**
 * Lazily summarises a ContextItem using a user's BYOK credentials.
 *
 * Returns a `SummariseResult` value object — the caller (typically
 * `SummariseContextItemJob`) writes the outcome back to the row. Missing
 * creds are not an error; they yield `Skipped`, and `bodyForContext()`
 * falls back to the truncated raw body so plan generation still works.
 */
class ContextCompressor
{
    public function summarise(ContextItem $item, ?User $actor): SummariseResult
    {
        $body = $this->resolveBody($item);
        if ($body === '') {
            return SummariseResult::skipped('Item has no compressible body.');
        }

        if ($actor === null) {
            return SummariseResult::skipped('No actor available for BYOK resolution.');
        }

        $providerName = $this->bindUserProvider($actor);
        if ($providerName === null) {
            return SummariseResult::skipped('No enabled BYOK credential available.');
        }

        try {
            $agent = new ContextSummariser($item, $body);
            $response = $agent->prompt($agent->buildPrompt(), provider: $providerName);
            $summary = trim((string) $response);

            if ($summary === '') {
                return SummariseResult::skipped('Provider returned an empty summary.');
            }

            return SummariseResult::ready($summary);
        } catch (Throwable $e) {
            return SummariseResult::failed($e->getMessage());
        } finally {
            Ai::forgetInstance($providerName);
            $providers = (array) config('ai.providers', []);
            unset($providers[$providerName]);
            config(['ai.providers' => $providers]);
        }
    }

    private function resolveBody(ContextItem $item): string
    {
        $type = $item->type instanceof ContextItemType ? $item->type : ContextItemType::tryFrom((string) $item->type);

        return match ($type) {
            ContextItemType::Text => trim((string) ($item->metadata['body'] ?? $item->description ?? '')),
            ContextItemType::File => trim((string) ($item->metadata['extracted_text'] ?? $item->description ?? '')),
            default => '',
        };
    }

    private function bindUserProvider(User $user): ?string
    {
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
                implode(' ', array_map(fn ($p, $i) => "when ? then {$i}", $providers, array_keys($providers))).
                ' end',
                $providers,
            )
            ->first();

        if ($credential === null) {
            return null;
        }

        $providerName = 'context-summariser-'.$user->getKey().'-'.$credential->provider.'-'.bin2hex(random_bytes(4));
        $base = (array) config('ai.providers.'.$credential->provider, ['driver' => $credential->provider]);
        $base['driver'] = $credential->provider;
        $base['key'] = $credential->api_key;

        config(['ai.providers.'.$providerName => $base]);
        Ai::forgetInstance($providerName);

        return $providerName;
    }
}
