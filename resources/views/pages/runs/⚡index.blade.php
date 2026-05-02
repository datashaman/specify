<?php

use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Run history')] class extends Component {
    use WithPagination;

    public int $project_id;

    public ?string $status = null;

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        $this->project_id = (int) $project;
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function runs()
    {
        $projectIds = Auth::user()->scopedProjectIds();

        return AgentRun::query()
            ->where(function ($q) use ($projectIds) {
                $q->whereHasMorph('runnable', [Subtask::class], function ($qq) use ($projectIds) {
                    $qq->whereHas('task.story.feature', fn ($qqq) => $qqq->whereIn('project_id', $projectIds));
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
            @php
                $duration = $run->started_at && $run->finished_at
                    ? $run->started_at->diffInSeconds($run->finished_at)
                    : null;
                $prMerged = $run->output['pull_request_merged'] ?? null;
                $prAction = $run->output['pull_request_action'] ?? null;
                $tokensIn = $run->tokens_input;
                $tokensOut = $run->tokens_output;
            @endphp
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">#{{ $run->id }}</flux:badge>
                    <flux:badge>{{ $run->status->value }}</flux:badge>
                    @if ($run->repo)
                        <flux:badge>{{ $run->repo->name }}</flux:badge>
                    @endif
                    @if ($run->working_branch)
                        <flux:badge>{{ $run->working_branch }}</flux:badge>
                    @endif
                    @if ($sha = $run->output['commit_sha'] ?? null)
                        <flux:badge>{{ substr($sha, 0, 7) }}</flux:badge>
                    @endif
                    @if ($prMerged === true)
                        <flux:badge>{{ __('PR merged') }}</flux:badge>
                    @elseif ($prAction === 'closed' && $prMerged === false)
                        <flux:badge>{{ __('PR closed') }}</flux:badge>
                    @elseif ($prAction !== null)
                        <flux:badge>{{ __('PR') }} {{ $prAction }}</flux:badge>
                    @endif
                    @if ($duration !== null)
                        <flux:badge>{{ $duration }}s</flux:badge>
                    @endif
                    @if ($tokensIn || $tokensOut)
                        <flux:badge>{{ $tokensIn ?? 0 }}/{{ $tokensOut ?? 0 }} {{ __('tok') }}</flux:badge>
                    @endif
                    @if ($run->finished_at)
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                    @endif
                </div>

                <flux:heading class="mt-2">
                    @if ($run->runnable instanceof App\Models\Subtask)
                        {{ $run->runnable->name }}
                    @elseif ($run->runnable instanceof App\Models\Story)
                        {{ __('Task generation for') }} {{ $run->runnable->name }}
                    @else
                        {{ __('Agent run') }}
                    @endif
                </flux:heading>

                @if ($run->runnable instanceof App\Models\Subtask && $run->runnable->task?->story)
                    <flux:text class="mt-1 text-xs text-zinc-500">
                        {{ $run->runnable->task->story->feature?->project?->name }}
                        &middot; {{ $run->runnable->task->story->name }}
                        &middot; {{ __('task') }} #{{ $run->runnable->task->position }} {{ $run->runnable->task->name }}
                        &middot; {{ __('subtask') }} #{{ $run->runnable->position }}
                    </flux:text>
                @endif

                @if ($url = $run->output['pull_request_url'] ?? null)
                    <flux:text class="mt-1">
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                    </flux:text>
                @endif

                @if ($run->error_message)
                    <flux:text class="mt-1 text-red-600">{{ $run->error_message }}</flux:text>
                @endif

                @if ($prError = $run->output['pull_request_error'] ?? null)
                    <flux:text class="mt-1 text-amber-600">{{ __('PR error') }}: {{ $prError }}</flux:text>
                @endif

                @if ($run->diff)
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Diff') }}</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ $run->diff }}</pre>
                    </details>
                @endif

                @if ($run->input)
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Prompt') }}</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ json_encode($run->input, JSON_PRETTY_PRINT) }}</pre>
                    </details>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->runs->links() }}
</div>
