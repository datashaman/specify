@php
    /** @var \App\Models\Task $task */
    $deps = $task->dependencies->map(fn ($d) => 'T'.$d->position);
@endphp

<div class="mt-4 border-l-2 border-zinc-100 pl-3 dark:border-zinc-800" data-task-id="{{ $task->id }}">
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <flux:badge size="sm">T{{ $task->position }}</flux:badge>
        <flux:badge size="sm">{{ $task->status->value }}</flux:badge>
        @if ($deps->isNotEmpty())
            <flux:badge size="sm">{{ __('depends on') }} {{ $deps->implode(', ') }}</flux:badge>
        @endif
    </div>

    <flux:heading class="mt-1" size="sm">
        <a
            href="{{ route('tasks.show', ['project' => $task->story->feature->project_id, 'story' => $task->story_id, 'task' => $task->id]) }}"
            wire:navigate
            class="hover:underline"
        >{{ $task->name }}</a>
    </flux:heading>

    @if ($task->description)
        <x-markdown :content="$task->description" class="mt-1 text-sm text-zinc-600 dark:text-zinc-400" />
    @endif

    @if ($task->subtasks->isNotEmpty())
        <div class="mt-3 flex flex-col gap-3 text-sm">
            @foreach ($task->subtasks->sortBy('position') as $sub)
                @php
                    $runs = $sub->agentRuns->sortByDesc('id')->values();
                    $latestRun = $runs->first();
                    $priorRuns = $runs->slice(1);
                    $appended = ! is_null($sub->proposed_by_run_id ?? null);
                @endphp
                <div data-subtask-id="{{ $sub->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm">T{{ $task->position }}.{{ $sub->position }}</flux:badge>
                        <a
                            href="{{ route('subtasks.show', ['project' => $task->story->feature->project_id, 'story' => $task->story_id, 'subtask' => $sub->id]) }}"
                            wire:navigate
                            class="font-medium hover:underline"
                        >{{ $sub->name }}</a>
                        <flux:badge size="sm">{{ $sub->status->value }}</flux:badge>
                        @if ($appended)
                            @php $provenanceLabel = __('Appended by Run').' #'.$sub->proposed_by_run_id; @endphp
                            <span
                                title="{{ $provenanceLabel }}"
                                aria-label="{{ $provenanceLabel }}"
                                role="img"
                                data-provenance
                                class="text-xs text-amber-600 dark:text-amber-400"
                            >
                                <span aria-hidden="true">+</span>
                                <span class="sr-only">{{ $provenanceLabel }}</span>
                            </span>
                        @endif
                    </div>
                    @if ($sub->description)
                        <x-markdown :content="$sub->description" class="mt-1 text-xs text-zinc-500" />
                    @endif

                    @if ($latestRun)
                        @php
                            $runUrl = route('runs.show', [
                                'project' => $task->story->feature->project_id,
                                'story' => $task->story_id,
                                'subtask' => $sub->id,
                                'run' => $latestRun->id,
                            ]);
                        @endphp
                        <div class="mt-2 overflow-hidden rounded-md border border-zinc-200 dark:border-zinc-700">
                            <a href="{{ $runUrl }}" wire:navigate class="flex flex-wrap items-center gap-2 bg-zinc-50 px-2 py-1 text-xs hover:bg-zinc-100 dark:bg-zinc-800/50 dark:hover:bg-zinc-800">
                                <flux:badge size="sm">{{ __('run') }} #{{ $latestRun->id }}</flux:badge>
                                <flux:badge size="sm">{{ $latestRun->status->value }}</flux:badge>
                                @if ($latestRun->finished_at)
                                    <flux:text class="ml-auto text-xs text-zinc-500">{{ $latestRun->finished_at->diffForHumans() }}</flux:text>
                                @endif
                            </a>
                            <div class="px-2 py-1.5">
                                @if ($url = $latestRun->output['pull_request_url'] ?? null)
                                    <flux:text class="text-xs">
                                        <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                                    </flux:text>
                                @endif
                                <x-run.error-output :message="$latestRun->error_message" />
                            </div>

                            <x-run.history-panel
                                :runs="$priorRuns"
                                :project-id="$task->story->feature->project_id"
                                :story-id="$task->story_id"
                                :subtask-id="$sub->id"
                            />
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
