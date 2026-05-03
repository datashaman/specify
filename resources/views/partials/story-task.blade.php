@php
    /** @var \App\Models\Task $task */
    $deps = $task->dependencies->map(fn ($d) => '#'.$d->position);
@endphp

<div class="mt-4 border-l-2 border-zinc-100 pl-3 dark:border-zinc-800" data-task-id="{{ $task->id }}">
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <flux:badge variant="solid" size="sm">#{{ $task->position }}</flux:badge>
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
        <ol class="mt-3 flex list-decimal flex-col gap-3 pl-5 text-sm marker:text-zinc-400">
            @foreach ($task->subtasks->sortBy('position') as $sub)
                @php
                    $runs = $sub->agentRuns->sortByDesc('id')->values();
                    $latestRun = $runs->first();
                    $priorRuns = $runs->slice(1);
                    $appended = ! is_null($sub->proposed_by_run_id ?? null);
                    $hasError = $latestRun && $latestRun->error_message;
                @endphp
                <li data-subtask-id="{{ $sub->id }}">
                    <div class="flex flex-wrap items-center gap-2">
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
                                @if ($hasError)
                                    <details>
                                        <summary class="cursor-pointer select-none text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400 dark:hover:text-rose-300">
                                            {{ __('Error output') }}
                                        </summary>
                                        <pre class="mt-1 max-h-48 overflow-auto rounded bg-rose-50 p-2 font-mono text-[11px] leading-snug text-rose-900 dark:bg-rose-950/40 dark:text-rose-200">{{ $latestRun->error_message }}</pre>
                                    </details>
                                @endif
                            </div>

                            @if ($priorRuns->isNotEmpty())
                                <details class="border-t border-zinc-200 dark:border-zinc-700">
                                    <summary class="cursor-pointer px-2 py-1 text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Run history') }} ({{ $priorRuns->count() }})</summary>
                                    <div class="flex flex-col">
                                        @foreach ($priorRuns as $run)
                                            @php
                                                $priorRunUrl = route('runs.show', [
                                                    'project' => $task->story->feature->project_id,
                                                    'story' => $task->story_id,
                                                    'subtask' => $sub->id,
                                                    'run' => $run->id,
                                                ]);
                                            @endphp
                                            <div class="border-t border-zinc-100 px-2 py-1 dark:border-zinc-800">
                                                <a href="{{ $priorRunUrl }}" wire:navigate class="flex flex-wrap items-center gap-2 text-xs hover:underline">
                                                    <flux:badge size="sm">#{{ $run->id }}</flux:badge>
                                                    <flux:badge size="sm">{{ $run->status->value }}</flux:badge>
                                                    @if ($run->finished_at)
                                                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                                                    @endif
                                                </a>
                                                @if ($url = $run->output['pull_request_url'] ?? null)
                                                    <flux:text class="mt-1 text-xs">
                                                        <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                                                    </flux:text>
                                                @endif
                                                @if ($run->error_message)
                                                    <details class="mt-1">
                                                        <summary class="cursor-pointer select-none text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400 dark:hover:text-rose-300">
                                                            {{ __('Error output') }}
                                                        </summary>
                                                        <pre class="mt-1 max-h-32 overflow-auto rounded bg-rose-50 p-2 font-mono text-[11px] leading-snug text-rose-900 dark:bg-rose-950/40 dark:text-rose-200">{{ $run->error_message }}</pre>
                                                    </details>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
