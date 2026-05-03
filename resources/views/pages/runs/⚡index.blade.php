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

new #[Title('Runs')] class extends Component {
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

@php
    $formatDuration = function (?int $seconds): ?string {
        if ($seconds === null) {
            return null;
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }
        $hours = intdiv($seconds, 3600);
        $mins = intdiv($seconds % 3600, 60);
        return $hours.'h '.$mins.'m';
    };
    $prettyJsonOrText = function (?string $body): string {
        if ($body === null || $body === '') {
            return '';
        }
        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }
        return $body;
    };
@endphp

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Runs') }}</flux:heading>

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
                $durationSeconds = $run->started_at && $run->finished_at
                    ? $run->started_at->diffInSeconds($run->finished_at)
                    : null;
                $duration = $formatDuration($durationSeconds);
                $prMerged = $run->output['pull_request_merged'] ?? null;
                $prAction = $run->output['pull_request_action'] ?? null;
                $tokensIn = $run->tokens_input;
                $tokensOut = $run->tokens_output;
                $sha = $run->output['commit_sha'] ?? null;
                $isFailed = $run->status->value === 'failed';
                $errorBody = $run->error_message;
                $prError = $run->output['pull_request_error'] ?? null;
                $hasOutput = $errorBody || $prError;
            @endphp
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">#{{ $run->id }}</flux:badge>
                    <flux:badge>{{ $run->status->value }}</flux:badge>
                    @if ($run->repo)
                        <flux:badge>{{ $run->repo->name }}</flux:badge>
                    @endif
                    @if ($run->working_branch)
                        <flux:text class="font-mono text-xs text-zinc-400 truncate max-w-[24rem]" :title="$run->working_branch">{{ $run->working_branch }}</flux:text>
                    @endif
                    @if ($prMerged === true)
                        <flux:badge color="emerald">{{ __('PR merged') }}</flux:badge>
                    @elseif ($prAction === 'closed' && $prMerged === false)
                        <flux:badge>{{ __('PR closed') }}</flux:badge>
                    @elseif ($prAction !== null)
                        <flux:badge>{{ __('PR') }} {{ $prAction }}</flux:badge>
                    @endif

                    <div class="ml-auto flex items-center gap-3 text-xs text-zinc-500">
                        @if ($sha)
                            <span class="font-mono">{{ substr($sha, 0, 7) }}</span>
                        @endif
                        @if ($duration)
                            <span class="tabular-nums">{{ $duration }}</span>
                        @endif
                        @if ($tokensIn || $tokensOut)
                            <span class="tabular-nums" title="{{ __('tokens in / out') }}">{{ $tokensIn ?? 0 }}↓ {{ $tokensOut ?? 0 }}↑</span>
                        @endif
                        @if ($run->finished_at)
                            <span>{{ $run->finished_at->diffForHumans(short: true) }}</span>
                        @endif
                    </div>
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
                        &middot; T{{ $run->runnable->task->position }} {{ $run->runnable->task->name }}
                        &middot; T{{ $run->runnable->task->position }}.{{ $run->runnable->position }}
                    </flux:text>
                @endif

                @if ($url = $run->output['pull_request_url'] ?? null)
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="mt-1 inline-flex">
                        <flux:badge size="sm" color="zinc" icon="arrow-top-right-on-square">{{ __('Pull request') }}</flux:badge>
                    </a>
                @endif

                @if ($hasOutput)
                    <details class="mt-3" {{ $isFailed ? 'open' : '' }}>
                        <summary class="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Output') }}</summary>
                        @if ($errorBody)
                            <div class="mt-2 rounded-md border border-rose-200 bg-rose-50 p-3 dark:border-rose-900/40 dark:bg-rose-950/30">
                                <x-markdown :content="$errorBody" class="text-rose-900 dark:text-rose-200" />
                            </div>
                        @endif
                        @if ($prError)
                            <div class="mt-2">
                                <div class="mb-1 text-xs uppercase tracking-wide text-amber-700 dark:text-amber-400">{{ __('PR error') }}</div>
                                <pre class="max-h-72 overflow-auto rounded bg-amber-50 p-3 font-mono text-xs leading-snug text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{{ $prettyJsonOrText($prError) }}</pre>
                            </div>
                        @endif
                    </details>
                @endif

                @if ($run->diff)
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Diff') }}</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-100 p-3 font-mono text-xs leading-snug dark:bg-zinc-900"><code>@foreach (preg_split("/\r?\n/", (string) $run->diff) as $line)<span @class([
                            'text-emerald-700 dark:text-emerald-300' => str_starts_with($line, '+') && ! str_starts_with($line, '+++'),
                            'text-rose-700 dark:text-rose-300' => str_starts_with($line, '-') && ! str_starts_with($line, '---'),
                            'text-sky-700 dark:text-sky-300 font-semibold' => str_starts_with($line, '@@'),
                            'text-zinc-500 dark:text-zinc-400 font-semibold' => str_starts_with($line, 'diff ') || str_starts_with($line, '+++') || str_starts_with($line, '---'),
                        ])>{{ $line }}</span>
@endforeach</code></pre>
                    </details>
                @endif

                @if ($run->input)
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Prompt') }}</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-100 p-3 font-mono text-xs leading-snug dark:bg-zinc-900">{{ json_encode($run->input, JSON_PRETTY_PRINT) }}</pre>
                    </details>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->runs->links() }}
</div>
