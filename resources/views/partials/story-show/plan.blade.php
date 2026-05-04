<section class="flex flex-col gap-3" data-section="plan">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <flux:heading size="lg">{{ __('Current plan') }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">
                {{ $acs->count() }} {{ __('ACs') }} · {{ $story->currentPlanTasks->count() }} {{ __('current-plan tasks') }} · {{ $subtaskCount }} {{ __('subtasks') }}
                @if ($story->currentPlan)
                    · {{ __('current') }} {{ $story->currentPlan->name ?? ('v'.$story->currentPlan->version) }}
                @endif
            </flux:text>
            @if ($story->currentPlan)
                <x-state-pill :state="$this->planPill['state']" :tally="$this->planPill['tally']" :label="__('Plan').' · '.$this->planPill['label']" />
            @endif
            @if ($repo)
                <flux:badge size="sm" icon="folder">{{ $repo->name }}</flux:badge>
            @endif
            @if ($branch)
                <flux:text class="font-mono text-xs text-zinc-400 truncate max-w-[24rem]" :title="$branch">{{ $branch }}</flux:text>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($acs->isNotEmpty() || $unmappedTasks->isNotEmpty())
                <div class="inline-flex rounded-md border border-zinc-200 p-0.5 text-xs dark:border-zinc-700" role="tablist" data-toggle="plan-mode" aria-label="{{ __('Plan view density') }}">
                    <button
                        type="button"
                        role="tab"
                        @click="planRunMode = false"
                        :aria-selected="!planRunMode ? 'true' : 'false'"
                        :class="!planRunMode ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-300'"
                        class="rounded px-2 py-0.5"
                    >{{ __('Compact') }}</button>
                    <button
                        type="button"
                        role="tab"
                        @click="planRunMode = true"
                        :aria-selected="planRunMode ? 'true' : 'false'"
                        :class="planRunMode ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-300'"
                        class="rounded px-2 py-0.5"
                    >{{ __('Expanded') }}</button>
                </div>
            @endif

            @if ($story->status === \App\Enums\StoryStatus::Approved && $story->currentPlanTasks->isEmpty() && ! $this->pendingPlanRun && $this->canApproveStory)
                <flux:button wire:click="generatePlan" wire:target="generatePlan" wire:loading.attr="disabled" variant="primary">
                    <span wire:loading.remove wire:target="generatePlan">{{ __('Generate plan') }}</span>
                    <span wire:loading wire:target="generatePlan">{{ __('Working…') }}</span>
                </flux:button>
            @endif
        </div>
    </div>

    @forelse ($acs as $ac)
        @php
            $acTasks = $tasksByAc->get($ac->id, collect())->sortBy('position');
        @endphp
        <flux:card data-ac="{{ $loop->iteration }}" data-ac-id="{{ $ac->id }}">
            <details
                class="group"
                x-data="{ open: {{ $shouldRunMode ? 'true' : 'false' }} || planRunMode }"
                x-effect="planRunMode ? (open = true) : ({{ $shouldRunMode ? 'true' : 'false' }} || (open = false))"
                :open="open"
                @toggle="open = $event.target.open"
            >
                <summary class="flex cursor-pointer list-none flex-wrap items-baseline gap-2 text-sm [&::-webkit-details-marker]:hidden">
                    <span class="text-zinc-400 transition-transform group-open:rotate-90" aria-hidden="true">▸</span>
                    <flux:badge size="sm">AC{{ $loop->iteration }}</flux:badge>
                    <span class="font-medium">{{ $ac->statement }}</span>
                </summary>

                @if ($acTasks->isEmpty())
                    <flux:text class="mt-2 text-xs text-zinc-500">{{ __('No current-plan task is mapped to this AC yet.') }}</flux:text>
                @else
                    @foreach ($acTasks as $task)
                        @include('partials.story-task', ['task' => $task])
                    @endforeach
                @endif
            </details>
        </flux:card>
    @empty
        @if ($this->pendingPlanRun)
            <flux:text class="text-zinc-500">{{ __('Generating plan…') }}</flux:text>
        @elseif ($story->acceptanceCriteria->isEmpty())
            <flux:text class="text-zinc-500">{{ __('No acceptance criteria yet.') }}</flux:text>
        @endif
    @endforelse

    @if ($unmappedTasks->isNotEmpty())
        <flux:card data-ac="unmapped">
            <details :open="planRunMode">
                <summary class="cursor-pointer text-sm font-medium">
                    {{ __('Current-plan tasks not mapped to an AC') }} ({{ $unmappedTasks->count() }})
                </summary>
                @foreach ($unmappedTasks as $task)
                    @include('partials.story-task', ['task' => $task])
                @endforeach
            </details>
        </flux:card>
    @endif

    @if ($acs->isEmpty() && $story->currentPlanTasks->isEmpty() && ! $this->pendingPlanRun && $story->status !== \App\Enums\StoryStatus::Approved)
        <flux:text class="text-zinc-500">{{ __('Current plan is generated once the story contract is approved.') }}</flux:text>
    @endif

    @if ($story->currentPlan && $story->currentPlan->status !== \App\Enums\PlanStatus::Approved && $story->currentPlanTasks->isNotEmpty())
        <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200" data-section="plan-approval-note">
            {{ __('Execution is blocked until the current plan is approved.') }}
        </div>
    @endif

    @if ($planGenRuns->isNotEmpty())
        <details class="mt-1">
            <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Plan generation runs') }} ({{ $planGenRuns->count() }})</summary>
            <div class="mt-2 flex flex-col gap-2">
                @foreach ($planGenRuns as $run)
                    <div class="rounded border border-zinc-200 px-2 py-1 dark:border-zinc-700">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <flux:badge size="sm">#{{ $run->id }}</flux:badge>
                            <flux:badge size="sm">{{ $run->status->value }}</flux:badge>
                            @if ($run->finished_at)
                                <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                            @endif
                        </div>
                        @if ($run->error_message)
                            <flux:text class="mt-1 text-xs text-red-600">{{ $run->error_message }}</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</section>
