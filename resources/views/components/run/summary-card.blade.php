@props([
    'run',
    'href' => null,
    'compact' => false,
    'wireNavigate' => true,
])

@php
    $durationSeconds = $run->started_at && $run->finished_at
        ? $run->started_at->diffInSeconds($run->finished_at)
        : null;

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
    $title = match (true) {
        $run->runnable instanceof \App\Models\Subtask => $run->runnable->name,
        $run->runnable instanceof \App\Models\Story => __('Task generation for').' '.$run->runnable->name,
        default => __('Agent run'),
    };
@endphp

@if ($compact)
    @if ($href)
        <a href="{{ $href }}" @if ($wireNavigate) wire:navigate @endif class="flex items-center gap-2 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
    @else
        <div class="flex items-center gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
    @endif
            <flux:badge variant="solid" size="sm">#{{ $run->id }}</flux:badge>
            <flux:badge size="sm">{{ $run->status->value }}</flux:badge>
            <flux:text class="truncate text-sm">{{ $title }}</flux:text>
            @if ($run->finished_at)
                <flux:text class="ml-auto shrink-0 text-xs text-zinc-500">{{ $run->finished_at->diffForHumans(short: true) }}</flux:text>
            @endif
    @if ($href)
        </a>
    @else
        </div>
    @endif
@else
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

        <flux:heading class="mt-2">{{ $title }}</flux:heading>

        @if ($run->runnable instanceof \App\Models\Subtask && $run->runnable->task?->plan?->story)
            <flux:text class="mt-1 text-xs text-zinc-500">
                {{ $run->runnable->task->plan->story->feature?->project?->name }}
                &middot; {{ $run->runnable->task->plan->story->name }}
                @if ($run->runnable->task->plan)
                    &middot; {{ __('plan') }} v{{ $run->runnable->task->plan->version }}
                @endif
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
@endif
