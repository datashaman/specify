@if (session('conflict_resolution') || session('conflict_resolution_error'))
    <div class="flex flex-col gap-3">
        @if (session('conflict_resolution'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">
                {{ session('conflict_resolution') }}
            </div>
        @endif

        @if (session('conflict_resolution_error'))
            <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200">
                {{ session('conflict_resolution_error') }}
            </div>
        @endif
    </div>
@endif
