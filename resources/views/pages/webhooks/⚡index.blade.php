<?php

use App\Models\Repo;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Webhooks')] class extends Component {
    use WithPagination;

    public ?string $event = null;

    public ?int $repo_id = null;

    #[Computed]
    public function repos()
    {
        $workspace = Auth::user()->currentWorkspace();

        return $workspace
            ? Repo::query()->where('workspace_id', $workspace->id)->orderBy('name')->get()
            : collect();
    }

    #[Computed]
    public function events()
    {
        $repoIds = $this->repos->pluck('id');

        return WebhookEvent::query()
            ->whereIn('repo_id', $repoIds)
            ->when($this->repo_id, fn ($q, $id) => $q->where('repo_id', $id))
            ->when($this->event, fn ($q, $e) => $q->where('event', $e))
            ->with(['repo', 'matchedRun'])
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Webhooks') }}</flux:heading>

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="repo_id" :placeholder="__('All repos')">
            <flux:select.option value="">{{ __('All repos') }}</flux:select.option>
            @foreach ($this->repos as $repo)
                <flux:select.option value="{{ $repo->id }}">{{ $repo->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="event" :placeholder="__('All events')">
            <flux:select.option value="">{{ __('All events') }}</flux:select.option>
            <flux:select.option value="pull_request">pull_request</flux:select.option>
            <flux:select.option value="push">push</flux:select.option>
            <flux:select.option value="ping">ping</flux:select.option>
        </flux:select>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->events as $evt)
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">#{{ $evt->id }}</flux:badge>
                    <flux:badge>{{ $evt->provider }}</flux:badge>
                    @if ($evt->repo)
                        <flux:badge>{{ $evt->repo->name }}</flux:badge>
                    @endif
                    <flux:badge>{{ $evt->event }}</flux:badge>
                    @if ($evt->action)
                        <flux:badge>{{ $evt->action }}</flux:badge>
                    @endif
                    @if (! $evt->signature_valid)
                        <flux:badge color="red">{{ __('invalid signature') }}</flux:badge>
                    @endif
                    @if ($evt->matched_run_id)
                        <flux:badge color="green">{{ __('matched run') }} #{{ $evt->matched_run_id }}</flux:badge>
                    @endif
                    <flux:text class="ml-auto text-xs text-zinc-500">{{ $evt->created_at?->diffForHumans() }}</flux:text>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Payload') }}</summary>
                    <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ json_encode($evt->payload, JSON_PRETTY_PRINT) }}</pre>
                </details>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No webhook deliveries yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->events->links() }}
</div>
