<div>
    <div class="mt-2 flex items-start justify-between gap-3">
        <flux:heading size="xl">{{ $story->name }}</flux:heading>
        <div class="flex items-center gap-2">
            @if ($this->canEditStory())
                <flux:button wire:click="startEdit" size="sm" icon="pencil-square">{{ __('Edit') }}</flux:button>
            @endif
            @if ($this->canDeleteStory())
                <flux:modal.trigger name="delete-story-modal">
                    <flux:button size="sm" variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>
    <div class="mt-3 grid gap-3 md:grid-cols-2" data-section="approval-tracks">
        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="text-[11px] uppercase tracking-wide text-zinc-500">{{ __('Story contract') }}</div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-state-pill :state="$pill['state']" :tally="$pill['tally']" :label="$pill['label']" />
                <flux:badge>{{ __('rev') }} {{ $story->revision }}</flux:badge>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-section="current-plan-track">
            <div class="text-[11px] uppercase tracking-wide text-zinc-500">{{ __('Current plan') }}</div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-state-pill :state="$planPill['state']" :tally="$planPill['tally']" :label="$planPill['label']" />
                @if ($story->currentPlan)
                    <flux:badge>{{ __('v') }}{{ $story->currentPlan->version }}</flux:badge>
                    <flux:badge>{{ __('rev') }} {{ $story->currentPlan->revision }}</flux:badge>
                @else
                    <flux:badge>{{ __('No plan yet') }}</flux:badge>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-2">
        @if ($story->creator)
            <flux:avatar
                size="xs"
                :name="$story->creator->name"
                :initials="$story->creator->initials()"
                :tooltip="$story->creator->name"
            />
        @endif
    </div>

    @if ($this->canDeleteStory())
        <flux:modal name="delete-story-modal" class="md:w-96">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Delete story?') }}</flux:heading>
                <flux:text>{{ __('This permanently removes the story and its acceptance criteria. Cannot be undone.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="deleteStory" variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    @php
    $primaryPr = $story->primaryPullRequest();
    $canResolve = $primaryPr
        && ($primaryPr['mergeable'] ?? null) === false
        && ($primaryPr['merged'] ?? null) !== true
        && $this->canApproveStory;
    $conflictRun = $this->activeConflictResolutionRun;
    $showResolveConflicts = $canResolve && ! $conflictRun;
@endphp

@if ($storyPrs->isNotEmpty())
        <div class="mt-3 flex flex-col gap-1.5" data-section="story-prs">
            <div class="flex items-baseline gap-2">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">
                    {{ trans_choice('Pull request|Pull requests', $storyPrs->count()) }}
                </flux:text>
                @if ($storyPrs->count() > 1)
                    <flux:text class="text-xs text-zinc-400">
                        {{ __('race candidates — reviewer picks the winner by merging it') }}
                    </flux:text>
                @endif
            </div>
            <div class="flex flex-col gap-1">
                @foreach ($storyPrs as $pr)
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <a
                            href="{{ $pr['url'] }}"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex"
                        >
                            <flux:badge size="sm" :color="$pr['merged'] === true ? 'emerald' : 'zinc'" icon="arrow-top-right-on-square">
                                @if ($pr['merged'] === true)
                                    {{ __('merged') }}
                                @elseif ($pr['merged'] === false)
                                    {{ __('open') }}
                                @else
                                    {{ __('PR') }}
                                @endif
                                @if ($pr['number'])
                                    #{{ $pr['number'] }}
                                @endif
                            </flux:badge>
                        </a>
                        @if (($pr['mergeable'] ?? null) === false && ($pr['merged'] ?? null) !== true)
                            <flux:badge size="sm" color="amber">{{ __('conflicted') }}</flux:badge>
                        @endif
                        @if ($pr['driver'])
                            <flux:badge size="sm" icon="cpu-chip">{{ $pr['driver'] }}</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($conflictRun && $conflictRun->runnable)
                @php
                    $crSub = $conflictRun->runnable;
                    $crRunUrl = route('runs.show', [
                        'project' => $project->id,
                        'story' => $story->id,
                        'subtask' => $crSub->id,
                        'run' => $conflictRun->id,
                    ]);
                @endphp
                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <flux:badge size="sm" color="amber">{{ __('Resolving merge conflicts (AI)…') }}</flux:badge>
                    <a href="{{ $crRunUrl }}" wire:navigate class="text-xs font-medium text-zinc-600 underline hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                        {{ __('Run') }} #{{ $conflictRun->id }}
                    </a>
                </div>
            @elseif ($showResolveConflicts)
                <div class="pt-1">
                    <flux:button
                        wire:click="resolveConflicts"
                        wire:target="resolveConflicts"
                        size="sm"
                        variant="primary"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="resolveConflicts">{{ __('Resolve conflicts (AI)') }}</span>
                        <span wire:loading wire:target="resolveConflicts">{{ __('Queueing…') }}</span>
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</div>
