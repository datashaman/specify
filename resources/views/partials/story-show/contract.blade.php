<section class="flex flex-col gap-3" data-section="story-contract">
    @if ($story->kind || $story->actor || $story->intent || $story->outcome || $story->currentPlan)
        <div class="flex flex-wrap gap-2 text-xs text-zinc-500">
            @if ($story->kind)
                <flux:badge size="sm">{{ $story->kind->value }}</flux:badge>
            @endif
            @if ($story->currentPlan)
                <flux:badge size="sm">{{ __('Current plan') }} v{{ $story->currentPlan->version }}</flux:badge>
                <x-state-pill :state="$planPill['state']" :tally="$planPill['tally']" :label="__('Plan').' · '.$planPill['label']" />
            @endif
        </div>
        @if ($story->actor || $story->intent || $story->outcome)
            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                @if ($story->actor)
                    <div><span class="font-medium">{{ __('As a') }}</span> {{ $story->actor }}</div>
                @endif
                @if ($story->intent)
                    <div class="mt-1"><span class="font-medium">{{ __('I want') }}</span> {{ $story->intent }}</div>
                @endif
                @if ($story->outcome)
                    <div class="mt-1"><span class="font-medium">{{ __('So that') }}</span> {{ $story->outcome }}</div>
                @endif
            </div>
        @endif
    @endif

    <x-markdown :content="$story->description" />

    @if ($story->scenarios->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:heading size="sm">{{ __('Scenarios') }}</flux:heading>
            @foreach ($story->scenarios->sortBy('position') as $scenario)
                <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                        <flux:badge size="sm">{{ __('Scenario') }} {{ $scenario->position }}</flux:badge>
                        @if ($scenario->acceptanceCriterion)
                            <flux:badge size="sm">{{ __('AC') }}{{ $scenario->acceptanceCriterion->position }}</flux:badge>
                        @endif
                    </div>
                    <div class="mt-1 font-medium">{{ $scenario->name }}</div>
                    @if ($scenario->given_text)
                        <div class="mt-1"><span class="font-medium">{{ __('Given') }}</span> {{ $scenario->given_text }}</div>
                    @endif
                    @if ($scenario->when_text)
                        <div class="mt-1"><span class="font-medium">{{ __('When') }}</span> {{ $scenario->when_text }}</div>
                    @endif
                    @if ($scenario->then_text)
                        <div class="mt-1"><span class="font-medium">{{ __('Then') }}</span> {{ $scenario->then_text }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($story->notes)
        <details>
            <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Notes') }}</summary>
            <x-markdown :content="$story->notes" class="mt-2" />
        </details>
    @endif
</section>
