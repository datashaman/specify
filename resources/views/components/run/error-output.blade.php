@props([
    'message',
    'maxHeight' => 'max-h-48',
    'summary' => __('Error output'),
    'open' => false,
    'wrapperClass' => '',
])

@if ($message)
    <details @if($open) open @endif class="{{ $wrapperClass }}">
        <summary class="cursor-pointer select-none text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400 dark:hover:text-rose-300">
            {{ $summary }}
        </summary>
        <pre class="mt-1 {{ $maxHeight }} overflow-auto rounded bg-rose-50 p-2 font-mono text-[11px] leading-snug text-rose-900 dark:bg-rose-950/40 dark:text-rose-200">{{ $message }}</pre>
    </details>
@endif
