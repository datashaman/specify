@props(['state' => 'draft'])

@php
    $colors = [
        'draft' => 'bg-zinc-300 dark:bg-zinc-700',
        'pending' => 'bg-amber-500',
        'approved' => 'bg-emerald-500',
        'changes_requested' => 'bg-rose-500',
        'rejected' => 'bg-zinc-500',
        'running' => 'bg-sky-500 animate-pulse motion-reduce:animate-none',
        'run_complete' => 'bg-emerald-600',
        'run_failed' => 'bg-rose-600',
    ];
    $cls = $colors[$state] ?? $colors['draft'];
@endphp

<div data-rail="{{ $state }}" {{ $attributes->merge(['class' => "w-1 self-stretch rounded-full $cls"]) }}></div>
