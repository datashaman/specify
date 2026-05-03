@props(['state' => 'draft', 'tally' => null, 'label' => null])

@php
    $palette = [
        'draft' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
        'pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'approved' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
        'changes_requested' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        'rejected' => 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300',
        'running' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'run_complete' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'run_failed' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    ];
    $defaults = [
        'draft' => __('Draft'),
        'pending' => __('Pending'),
        'approved' => __('Approved'),
        'changes_requested' => __('Changes requested'),
        'rejected' => __('Rejected'),
        'running' => __('Running'),
        'run_complete' => __('Run complete'),
        'run_failed' => __('Run failed'),
    ];
    $text = $label ?? ($defaults[$state] ?? __('Draft'));
    $cls = $palette[$state] ?? $palette['draft'];
@endphp

<span data-pill="{{ $state }}" {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium $cls"]) }}>
    {{ $text }}@if ($tally !== null) <span class="opacity-70">·</span> <span class="tabular-nums">{{ $tally }}</span>@endif
</span>
