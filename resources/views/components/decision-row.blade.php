@props(['approval'])

@php
    $decision = $approval->decision;
    $glyph = match ($decision->value) {
        'approve' => '✓',
        'changes_requested' => '↻',
        'reject' => '✗',
        'revoke' => '↶',
        default => '·',
    };
    $tone = match ($decision->value) {
        'approve' => 'text-emerald-600 dark:text-emerald-400',
        'changes_requested' => 'text-amber-600 dark:text-amber-400',
        'reject' => 'text-rose-600 dark:text-rose-400',
        'revoke' => 'text-zinc-500 dark:text-zinc-400',
        default => 'text-zinc-500',
    };
@endphp

<div data-decision="{{ $decision->value }}" class="flex items-start gap-2 text-sm">
    <span class="mt-0.5 font-mono {{ $tone }}" aria-hidden="true">{{ $glyph }}</span>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-medium">{{ $approval->approver?->name ?? __('unknown') }}</span>
            <span class="text-xs text-zinc-500">{{ $decision->value }}</span>
            @if ($approval->story_revision)
                <span class="text-xs text-zinc-500">rev {{ $approval->story_revision }}</span>
            @endif
            <span class="ml-auto text-xs text-zinc-400">{{ $approval->created_at?->diffForHumans() }}</span>
        </div>
        @if ($approval->notes)
            <div class="mt-0.5 whitespace-pre-wrap text-xs text-zinc-600 dark:text-zinc-300">{{ $approval->notes }}</div>
        @endif
    </div>
</div>
