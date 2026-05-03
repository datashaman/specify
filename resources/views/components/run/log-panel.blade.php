@props([
    'events',
    'stdout' => null,
    'stderr' => null,
    'poll' => false,
])

@if ($events->isNotEmpty())
    <div
        class="max-h-[40rem] overflow-auto rounded-md border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs leading-snug dark:border-zinc-700 dark:bg-zinc-900"
        data-section="run-events"
        @if ($poll) wire:poll.2s @endif
    >
        @foreach ($events as $event)
            <div class="flex gap-2" data-event-seq="{{ $event->seq }}" data-event-type="{{ $event->type }}">
                <span class="w-12 shrink-0 text-right text-zinc-400">{{ $event->seq }}</span>
                <span class="w-16 shrink-0 truncate text-zinc-500">{{ $event->phase }}</span>
                <span class="w-16 shrink-0 truncate {{ $event->type === 'stderr' || $event->type === 'error' ? 'text-rose-600 dark:text-rose-400' : ($event->type === 'sentinel' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500') }}">{{ $event->type }}</span>
                <span class="min-w-0 flex-1 whitespace-pre-wrap break-words">{{ $event->payload['line'] ?? json_encode($event->payload) }}</span>
            </div>
        @endforeach
    </div>
@elseif ($stdout || $stderr)
    @if ($stdout)
        <div>
            <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">{{ __('stdout') }}</div>
            <pre class="max-h-[32rem] overflow-auto rounded-md border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs leading-snug dark:border-zinc-700 dark:bg-zinc-900">{{ $stdout }}</pre>
        </div>
    @endif
    @if ($stderr)
        <div>
            <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">{{ __('stderr') }}</div>
            <pre class="max-h-[32rem] overflow-auto rounded-md border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs leading-snug dark:border-zinc-700 dark:bg-zinc-900">{{ $stderr }}</pre>
        </div>
    @endif
@endif
