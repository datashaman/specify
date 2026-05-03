@props([
    'runs',
    'projectId',
    'storyId',
    'subtaskId',
])

@if ($runs->isNotEmpty())
    <details class="border-t border-zinc-200 dark:border-zinc-700">
        <summary class="cursor-pointer px-2 py-1 text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Run history') }} ({{ $runs->count() }})</summary>
        <div class="flex flex-col">
            @foreach ($runs as $run)
                @php
                    $priorRunUrl = route('runs.show', [
                        'project' => $projectId,
                        'story' => $storyId,
                        'subtask' => $subtaskId,
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
                    <x-run.error-output :message="$run->error_message" wrapper-class="mt-1" max-height="max-h-32" />
                </div>
            @endforeach
        </div>
    </details>
@endif
