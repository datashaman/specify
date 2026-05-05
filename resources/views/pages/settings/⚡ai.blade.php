<?php

use App\Models\UserAiCredential;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI key settings')] class extends Component {
    public string $default_provider = '';

    public string $anthropic_api_key = '';
    public string $anthropic_model = '';
    public bool $anthropic_enabled = false;
    public bool $anthropic_configured = false;

    public string $openai_api_key = '';
    public string $openai_model = '';
    public bool $openai_enabled = false;
    public bool $openai_configured = false;

    public function mount(): void
    {
        $this->loadCredentials();
    }

    public function saveProvider(string $provider): void
    {
        $this->guardProvider($provider);

        $keyField = "{$provider}_api_key";
        $modelField = "{$provider}_model";
        $enabledField = "{$provider}_enabled";

        $validated = Validator::make([
            'api_key' => $this->{$keyField},
            'model' => $this->{$modelField},
            'enabled' => $this->{$enabledField},
        ], [
            'api_key' => ['nullable', 'string', 'min:8', 'max:4000'],
            'model' => ['nullable', 'string', 'max:120'],
            'enabled' => ['boolean'],
        ])->validate();

        $user = Auth::user();
        $credential = $user->aiCredentials()->where('provider', $provider)->first();
        if ($credential === null && trim((string) $validated['api_key']) === '') {
            $this->addError($keyField, __('Enter an API key to configure this provider.'));

            return;
        }

        $attributes = [
            'model' => trim((string) $validated['model']) ?: null,
            'enabled' => (bool) $validated['enabled'],
        ];
        if (trim((string) $validated['api_key']) !== '') {
            $attributes['api_key'] = trim((string) $validated['api_key']);
        }

        $user->aiCredentials()->updateOrCreate(['provider' => $provider], $attributes);

        if (! in_array($user->ai_provider, UserAiCredential::supportedProviders(), true)) {
            $user->forceFill(['ai_provider' => $provider])->save();
        }

        $this->{$keyField} = '';
        $this->loadCredentials();

        Flux::toast(variant: 'success', text: __('AI key saved.'));
    }

    public function removeProvider(string $provider): void
    {
        $this->guardProvider($provider);

        $user = Auth::user();
        $user->aiCredentials()->where('provider', $provider)->delete();

        if ($user->ai_provider === $provider) {
            $next = $user->aiCredentials()->where('enabled', true)->orderBy('provider')->value('provider');
            $user->forceFill(['ai_provider' => $next])->save();
        }

        $this->loadCredentials();

        Flux::toast(variant: 'success', text: __('AI key removed.'));
    }

    public function setDefaultProvider(): void
    {
        $validated = Validator::make([
            'provider' => $this->default_provider,
        ], [
            'provider' => ['required', Rule::in(UserAiCredential::supportedProviders())],
        ])->validate();

        $user = Auth::user();
        $exists = $user->aiCredentials()
            ->where('provider', $validated['provider'])
            ->where('enabled', true)
            ->exists();

        if (! $exists) {
            $this->addError('default_provider', __('Choose an enabled provider.'));

            return;
        }

        $user->forceFill(['ai_provider' => $validated['provider']])->save();
        $this->loadCredentials();

        Flux::toast(variant: 'success', text: __('Default AI provider updated.'));
    }

    private function loadCredentials(): void
    {
        $user = Auth::user()->fresh('aiCredentials');
        $this->default_provider = (string) ($user->ai_provider ?? '');

        foreach (UserAiCredential::supportedProviders() as $provider) {
            $credential = $user->aiCredentials->firstWhere('provider', $provider);
            $this->{$provider.'_api_key'} = '';
            $this->{$provider.'_model'} = (string) ($credential?->model ?? '');
            $this->{$provider.'_enabled'} = (bool) ($credential?->enabled ?? false);
            $this->{$provider.'_configured'} = $credential !== null;
        }
    }

    private function guardProvider(string $provider): void
    {
        abort_unless(in_array($provider, UserAiCredential::supportedProviders(), true), 404);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('AI key settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('AI keys')" :subheading="__('Manage your personal provider keys for agent runs')">
        <div class="space-y-10">
            <form wire:submit="setDefaultProvider" class="space-y-4">
                <flux:select wire:model="default_provider" :label="__('Default provider')">
                    <flux:select.option value="">{{ __('Choose provider') }}</flux:select.option>
                    <flux:select.option value="anthropic" :disabled="! $anthropic_enabled">{{ __('Anthropic') }}</flux:select.option>
                    <flux:select.option value="openai" :disabled="! $openai_enabled">{{ __('OpenAI') }}</flux:select.option>
                </flux:select>

                <flux:button type="submit" variant="primary">{{ __('Save default') }}</flux:button>
            </form>

            <flux:separator />

            <form wire:submit="saveProvider('anthropic')" class="space-y-4">
                <div>
                    <flux:heading>{{ __('Anthropic') }}</flux:heading>
                    <flux:text variant="subtle">{{ $anthropic_configured ? __('Key configured') : __('No key configured') }}</flux:text>
                </div>

                <flux:input wire:model="anthropic_api_key" :label="$anthropic_configured ? __('Replace API key') : __('API key')" type="password" autocomplete="off" viewable />
                <flux:input wire:model="anthropic_model" :label="__('Model override')" placeholder="claude-sonnet-4-6" />
                <flux:checkbox wire:model="anthropic_enabled" :label="__('Enabled')" />

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Save Anthropic') }}</flux:button>
                    @if ($anthropic_configured)
                        <flux:button type="button" variant="danger" wire:click="removeProvider('anthropic')">{{ __('Remove') }}</flux:button>
                    @endif
                </div>
            </form>

            <flux:separator />

            <form wire:submit="saveProvider('openai')" class="space-y-4">
                <div>
                    <flux:heading>{{ __('OpenAI') }}</flux:heading>
                    <flux:text variant="subtle">{{ $openai_configured ? __('Key configured') : __('No key configured') }}</flux:text>
                </div>

                <flux:input wire:model="openai_api_key" :label="$openai_configured ? __('Replace API key') : __('API key')" type="password" autocomplete="off" viewable />
                <flux:input wire:model="openai_model" :label="__('Model override')" placeholder="gpt-5.4" />
                <flux:checkbox wire:model="openai_enabled" :label="__('Enabled')" />

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Save OpenAI') }}</flux:button>
                    @if ($openai_configured)
                        <flux:button type="button" variant="danger" wire:click="removeProvider('openai')">{{ __('Remove') }}</flux:button>
                    @endif
                </div>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
