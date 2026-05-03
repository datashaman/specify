<?php

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use App\Models\Subtask;
use App\Services\ExecutionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Run')] class extends Component {
    public int $project_id;

    public int $story_id;

    public int $subtask_id;

    public int $run_id;

    public string $tab = 'logs';

    public function mount(int $project, int $story, int $subtask, int $run): void
    {
        $this->project_id = (int) $project;
        $this->story_id = (int) $story;
        $this->subtask_id = (int) $subtask;
        $this->run_id = (int) $run;

        abort_unless($this->run, 404);

        $user = Auth::user();
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    public function cancel(ExecutionService $execution): void
    {
        $run = $this->run;
        abort_unless($run, 404);

        if ($run->isTerminal()) {
            return;
        }

        $execution->cancelRun($run, 'Cancelled from run console.');
        unset($this->run);
    }

    public function retryPr(ExecutionService $execution): void
    {
        $run = $this->run;
        abort_unless($run, 404);

        try {
            $execution->retryPullRequestOpen($run);
        } catch (\RuntimeException $e) {
            // Surface but don't crash the page — invariant violations
            // (already-open PR, non-Succeeded run) are expected user errors.
            session()->flash('pr_retry_error', $e->getMessage());

            return;
        }

        session()->flash('pr_retry_dispatched', __('PR retry dispatched.'));
        unset($this->run);
    }

    public function retry(ExecutionService $execution): void
    {
        $run = $this->run;
        abort_unless($run, 404);

        if (! $run->isTerminal() || ! $run->status->isFailure()) {
            return;
        }
        if ($run->kind === AgentRunKind::RespondToReview || $run->kind === AgentRunKind::ResolveConflicts) {
            return;
        }

        $subtask = $run->runnable;
        if (! $subtask instanceof Subtask) {
            return;
        }

        try {
            $newRun = $execution->retrySubtaskExecution($subtask, $run);
        } catch (\RuntimeException $e) {
            session()->flash('retry_error', $e->getMessage());

            return;
        }

        $this->redirect(route('runs.show', [
            'project' => $this->project_id,
            'story' => $this->story_id,
            'subtask' => $this->subtask_id,
            'run' => $newRun->id,
        ]), navigate: true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, AgentRunEvent>
     */
    #[Computed]
    public function events(): \Illuminate\Support\Collection
    {
        if (! $this->run) {
            return collect();
        }

        return AgentRunEvent::query()
            ->where('agent_run_id', $this->run->getKey())
            ->orderBy('seq')
            ->get();
    }

    #[Computed]
    public function run(): ?AgentRun
    {
        $accessible = Auth::user()->accessibleProjectIds();

        return AgentRun::query()
            ->where('id', $this->run_id)
            ->where('runnable_type', Subtask::class)
            ->where('runnable_id', $this->subtask_id)
            ->whereHasMorph('runnable', [Subtask::class], fn ($q) => $q
                ->whereHas('task.story', fn ($qq) => $qq->where('id', $this->story_id))
                ->whereHas('task.story.feature', fn ($qq) => $qq
                    ->where('project_id', $this->project_id)
                    ->whereIn('project_id', $accessible)
                )
            )
            ->with(['runnable.task.story.feature.project', 'repo'])
            ->first();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['logs', 'diff', 'pr'], true) ? $tab : 'logs';
    }
}; ?>

<div class="flex p-6">
    @if (! $this->run)
        <flux:text class="text-zinc-500">{{ __('Run not found.') }}</flux:text>
    @else
        @php
            $run = $this->run;
            $subtask = $run->runnable;
            $task = $subtask->task;
            $story = $task->story;
            $feature = $story->feature;
            $project = $feature->project;
            $rail = match ($run->status) {
                AgentRunStatus::Succeeded => 'run_complete',
                AgentRunStatus::Running, AgentRunStatus::Queued => 'running',
                AgentRunStatus::Failed, AgentRunStatus::Aborted, AgentRunStatus::Cancelled => 'run_failed',
                default => 'draft',
            };
            $canCancel = ! $run->isTerminal();
            $cancelPending = $canCancel && (bool) $run->cancel_requested;
            // Review-response runs (ADR-0008) re-fire automatically on the
            // next review event and are explicitly not retryable through
            // the manual chain (ADR-0010).
            $canRetry = $run->isTerminal()
                && $run->status->isFailure()
                && $run->kind !== AgentRunKind::RespondToReview
                && $run->kind !== AgentRunKind::ResolveConflicts;
            $duration = $run->started_at && $run->finished_at
                ? $run->started_at->diffInSeconds($run->finished_at)
                : null;
            $prUrl = $run->output['pull_request_url'] ?? null;
            $prError = $run->output['pull_request_error'] ?? null;
            $diff = $run->diff;
        @endphp

        <x-rail :state="$rail" class="mr-4" />

        <div class="flex min-w-0 max-w-5xl flex-1 flex-col gap-6">
            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-sm text-zinc-500" data-section="breadcrumb">
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $project->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('features.show', ['project' => $project, 'feature' => $feature]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $feature->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('stories.show', ['project' => $project, 'story' => $story]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $story->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('subtasks.show', ['project' => $project, 'story' => $story, 'subtask' => $subtask]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $subtask->name }}</a>
                <span aria-hidden="true">›</span>
                <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">run #{{ $run->id }}</span>
            </nav>

            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl">{{ __('Run') }} #{{ $run->id }}</flux:heading>
                    <x-state-pill :state="$rail" :label="$run->status->value" />
                    @if ($run->kind && $run->kind->value !== 'execute')
                        <flux:badge color="purple">{{ $run->kind->value }}</flux:badge>
                    @endif
                    @if ($run->retry_of_id)
                        <a
                            href="{{ route('runs.show', ['project' => $project, 'story' => $story, 'subtask' => $subtask, 'run' => $run->retry_of_id]) }}"
                            wire:navigate
                            class="text-xs text-zinc-500 underline hover:text-zinc-700 dark:hover:text-zinc-300"
                        >{{ __('retry of') }} #{{ $run->retry_of_id }}</a>
                    @endif
                    @if ($cancelPending)
                        <flux:badge size="sm" color="amber" icon="clock">{{ __('cancel pending') }}</flux:badge>
                    @endif
                    @if ($canCancel)
                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="x-mark"
                            wire:click="cancel"
                            wire:confirm="{{ __('Cancel this run? In-flight tool calls finish; the pipeline stops at the next phase boundary.') }}"
                            :disabled="$cancelPending"
                            class="ml-auto"
                            data-action="cancel-run"
                        >{{ $cancelPending ? __('Cancelling…') : __('Cancel run') }}</flux:button>
                    @endif
                    @if ($canRetry)
                        <flux:button
                            size="sm"
                            variant="primary"
                            icon="arrow-path"
                            wire:click="retry"
                            wire:confirm="{{ __('Dispatch a fresh AgentRun for this subtask? The new run is authorised against the current StoryApproval.') }}"
                            class="ml-auto"
                            data-action="retry-run"
                        >{{ __('Retry') }}</flux:button>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    @if ($run->executor_driver)
                        <flux:badge size="sm" icon="cpu-chip">{{ $run->executor_driver }}</flux:badge>
                    @endif
                    @if ($run->repo)
                        <flux:badge size="sm" icon="folder">{{ $run->repo->name }}</flux:badge>
                    @endif
                    @if ($run->working_branch)
                        <flux:badge size="sm" icon="code-bracket">{{ $run->working_branch }}</flux:badge>
                    @endif
                    @if ($duration !== null)
                        <flux:badge size="sm" icon="clock">{{ $duration }}s</flux:badge>
                    @endif
                    @if ($run->started_at)
                        <flux:text class="text-xs text-zinc-500">{{ __('started') }} {{ $run->started_at->diffForHumans() }}</flux:text>
                    @endif
                    @if ($run->finished_at)
                        <flux:text class="text-xs text-zinc-500">· {{ __('finished') }} {{ $run->finished_at->diffForHumans() }}</flux:text>
                    @endif
                </div>
                @if ($run->tokens_input || $run->tokens_output)
                    <div class="mt-1 text-xs text-zinc-500">
                        {{ __('Tokens:') }} {{ number_format($run->tokens_input ?? 0) }} {{ __('in') }} · {{ number_format($run->tokens_output ?? 0) }} {{ __('out') }}
                    </div>
                @endif
            </div>

            @if (session('retry_error'))
                <flux:callout icon="exclamation-triangle" color="rose">
                    <flux:callout.heading>{{ __('Retry failed') }}</flux:callout.heading>
                    <flux:callout.text>{{ session('retry_error') }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($run->error_message)
                <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 dark:border-rose-900/40 dark:bg-rose-950/30">
                    <x-run.error-output :message="$run->error_message" :open="true" max-height="max-h-80" />
                </div>
            @endif

            @if ($prError)
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.heading>{{ __('PR creation failed') }}</flux:callout.heading>
                    <flux:callout.text>{{ $prError }}</flux:callout.text>
                    <flux:callout.text class="text-xs">{{ __('Per ADR-0004, PR-after-push is non-fatal: the run still succeeded; the PR can be opened manually.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex flex-col gap-3" data-section="run-tabs">
                <div class="inline-flex rounded-md border border-zinc-200 p-0.5 text-xs dark:border-zinc-700" role="tablist">
                    @foreach (['logs' => __('Logs'), 'diff' => __('Diff'), 'pr' => __('Pull request')] as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            wire:click="setTab('{{ $key }}')"
                            aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            class="rounded px-3 py-1 {{ $tab === $key ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-300' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                @if ($tab === 'logs')
                    @php
                        $events = $this->events;
                        $stdout = $run->output['stdout'] ?? null;
                        $stderr = $run->output['stderr'] ?? null;
                    @endphp
                    @if ($events->isNotEmpty() || $stdout || $stderr)
                        <x-run.log-panel :events="$events" :stdout="$stdout" :stderr="$stderr" :poll="! $run->isTerminal()" />
                    @elseif (! $run->isTerminal())
                        <flux:text class="text-zinc-500" wire:poll.2s>{{ __('Waiting for the executor to emit events…') }}</flux:text>
                    @else
                        <flux:text class="text-zinc-500">{{ __('No log output captured for this run.') }}</flux:text>
                    @endif
                @elseif ($tab === 'diff')
                    @if ($diff)
                        <pre class="max-h-[40rem] overflow-auto rounded-md border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs leading-snug dark:border-zinc-700 dark:bg-zinc-900">{{ $diff }}</pre>
                    @else
                        <flux:text class="text-zinc-500">{{ __('No diff captured for this run.') }}</flux:text>
                    @endif
                @elseif ($tab === 'pr')
                    @if (session('pr_retry_dispatched'))
                        <flux:callout icon="check-circle" color="emerald">
                            <flux:callout.text>{{ session('pr_retry_dispatched') }}</flux:callout.text>
                        </flux:callout>
                    @endif
                    @if (session('pr_retry_error'))
                        <flux:callout icon="exclamation-triangle" color="rose">
                            <flux:callout.text>{{ session('pr_retry_error') }}</flux:callout.text>
                        </flux:callout>
                    @endif
                    @if ($prUrl)
                        <flux:card>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm">{{ __('PR') }}</flux:badge>
                                <a href="{{ $prUrl }}" target="_blank" rel="noopener" class="text-sm underline">{{ $prUrl }}</a>
                                @if (($action = $run->output['pull_request_action'] ?? null))
                                    <flux:badge size="sm">{{ $action }}</flux:badge>
                                @endif
                                @if (! is_null($run->output['pull_request_merged'] ?? null))
                                    <flux:badge size="sm" color="{{ $run->output['pull_request_merged'] ? 'emerald' : 'zinc' }}">
                                        {{ $run->output['pull_request_merged'] ? __('merged') : __('open') }}
                                    </flux:badge>
                                @endif
                            </div>
                        </flux:card>
                    @elseif ($prError && $run->status === AgentRunStatus::Succeeded)
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button
                                size="sm"
                                variant="primary"
                                icon="arrow-path"
                                wire:click="retryPr"
                                data-action="retry-pr"
                            >{{ __('Retry PR open') }}</flux:button>
                            <flux:text class="text-xs text-zinc-500">{{ __('Re-attempt PR creation; idempotent — adopts an existing PR if one is already open for this branch.') }}</flux:text>
                        </div>
                    @else
                        <flux:text class="text-zinc-500">{{ __('No pull request opened for this run.') }}</flux:text>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
