<?php

use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Run history')] class extends Component {
    use WithPagination;

    public ?string $status = null;

    #[Computed]
    public function runs()
    {
        $projectIds = Auth::user()->accessibleProjectIds();

        return AgentRun::query()
            ->where(function ($q) use ($projectIds) {
                $q->whereHasMorph('runnable', [Task::class], function ($qq) use ($projectIds) {
                    $qq->whereHas('plan.story.feature', fn ($qqq) => $qqq->whereIn('project_id', $projectIds));
                })
                ->orWhereHasMorph('runnable', [Story::class], function ($qq) use ($projectIds) {
                    $qq->whereHas('feature', fn ($qqq) => $qqq->whereIn('project_id', $projectIds));
                });
            })
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->with('runnable', 'repo')
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Run history') }}</flux:heading>

    <div class="flex gap-2">
        <flux:select wire:model.live="status" :placeholder="__('All statuses')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            <flux:select.option value="queued">{{ __('Queued') }}</flux:select.option>
            <flux:select.option value="running">{{ __('Running') }}</flux:select.option>
            <flux:select.option value="succeeded">{{ __('Succeeded') }}</flux:select.option>
            <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
            <flux:select.option value="aborted">{{ __('Aborted') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->runs as $run)
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">#{{ $run->id }}</flux:badge>
                    <flux:badge>{{ $run->status->value }}</flux:badge>
                    @if ($run->working_branch)
                        <flux:badge>{{ $run->working_branch }}</flux:badge>
                    @endif
                    @if ($sha = $run->output['commit_sha'] ?? null)
                        <flux:badge>{{ substr($sha, 0, 7) }}</flux:badge>
                    @endif
                    @if ($run->finished_at)
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                    @endif
                </div>

                <flux:heading class="mt-2">
                    @if ($run->runnable instanceof App\Models\Task)
                        {{ $run->runnable->name }}
                    @elseif ($run->runnable instanceof App\Models\Story)
                        {{ __('Plan generation for') }} {{ $run->runnable->name }}
                    @else
                        {{ __('Agent run') }}
                    @endif
                </flux:heading>

                @if ($url = $run->output['pull_request_url'] ?? null)
                    <flux:text class="mt-1">
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                    </flux:text>
                @endif

                @if ($run->error_message)
                    <flux:text class="mt-1 text-red-600">{{ $run->error_message }}</flux:text>
                @endif

                @if ($run->diff)
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Diff') }}</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ $run->diff }}</pre>
                    </details>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->runs->links() }}
</div>
