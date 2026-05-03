@if ($showRail)
    <aside class="flex w-full flex-col gap-4 lg:w-80 lg:flex-none" data-rail-aside>
        <section data-section="approval-tracks-rail" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Approval tracks') }}</flux:text>

            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-track="story">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <div class="text-sm font-medium">{{ __('Story contract') }}</div>
                        <div class="text-xs text-zinc-500">{{ __('What is being built and why.') }}</div>
                    </div>
                    <x-state-pill :state="$pill['state']" :tally="$pill['tally']" :label="$pill['label']" />
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-track="plan">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <div class="text-sm font-medium">{{ __('Current plan') }}</div>
                        <div class="text-xs text-zinc-500">
                            @if ($currentPlan)
                                {{ $currentPlan->name ?? __('Plan') }} · {{ __('execution gate') }}
                            @else
                                {{ __('No current plan selected.') }}
                            @endif
                        </div>
                    </div>
                    <x-state-pill :state="$planPill['state']" :tally="$planPill['tally']" :label="$planPill['label']" />
                </div>
            </div>
        </section>

        @if ($decisionVisible)
            <section data-section="decision" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Actions') }}</flux:text>

                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2" data-section="story-actions">
                        <flux:text class="text-xs font-medium text-zinc-500">{{ __('Story contract actions') }}</flux:text>
                        @if ($hasDraftSubmit)
                            <flux:button wire:click="submit" wire:target="submit" wire:loading.attr="disabled" variant="primary" class="w-full">
                                <span wire:loading.remove wire:target="submit">{{ $autoPromotes ? __('Submit & generate plan') : __('Submit story for approval') }}</span>
                                <span wire:loading wire:target="submit">{{ __('Working…') }}</span>
                            </flux:button>
                        @endif

                        @if ($hasAutoStart)
                            <flux:button wire:click="startExecution" wire:target="startExecution" wire:loading.attr="disabled" variant="primary" class="w-full">
                                <span wire:loading.remove wire:target="startExecution">{{ __('Advance story approval') }}</span>
                                <span wire:loading wire:target="startExecution">{{ __('Working…') }}</span>
                            </flux:button>
                        @elseif ($hasApprovalActions)
                            @if ($userApproved)
                                <flux:button wire:click="decide('revoke')" wire:target="decide" wire:loading.attr="disabled" class="w-full">{{ __('Revoke story approval') }}</flux:button>
                            @else
                                <flux:button wire:click="decide('approve')" wire:target="decide" wire:loading.attr="disabled" variant="primary" class="w-full">{{ __('Approve story contract') }}</flux:button>
                            @endif
                            <flux:button wire:click="decide('changes_requested')" wire:target="decide" wire:loading.attr="disabled" class="w-full">{{ __('Request story changes') }}</flux:button>
                            <flux:button wire:click="decide('reject')" wire:target="decide" wire:loading.attr="disabled" variant="danger" class="w-full">{{ __('Reject story contract') }}</flux:button>
                        @endif

                        @if ($needsApprovalNote)
                            <flux:textarea wire:model.defer="approvalNote" :label="__('Story decision notes (optional)')" rows="3" />
                        @endif

                        @if ($blockedNotice)
                            <flux:text class="text-xs text-amber-600">{{ __('You authored this story; the policy disallows self-approval.') }}</flux:text>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2" data-section="plan-actions">
                        <flux:text class="text-xs font-medium text-zinc-500">{{ __('Plan actions') }}</flux:text>
                        @if ($hasPlanSubmit)
                            <flux:button wire:click="submitPlan" wire:target="submitPlan" wire:loading.attr="disabled" variant="primary" class="w-full">
                                <span wire:loading.remove wire:target="submitPlan">{{ __('Submit current plan for approval') }}</span>
                                <span wire:loading wire:target="submitPlan">{{ __('Working…') }}</span>
                            </flux:button>
                        @elseif ($hasPlanApprovalActions)
                            @if ($userApprovedPlan)
                                <flux:button wire:click="decidePlan('revoke')" wire:target="decidePlan" wire:loading.attr="disabled" class="w-full">{{ __('Revoke plan approval') }}</flux:button>
                            @else
                                <flux:button wire:click="decidePlan('approve')" wire:target="decidePlan" wire:loading.attr="disabled" variant="primary" class="w-full">{{ __('Approve current plan') }}</flux:button>
                            @endif
                            <flux:button wire:click="decidePlan('changes_requested')" wire:target="decidePlan" wire:loading.attr="disabled" class="w-full">{{ __('Request plan changes') }}</flux:button>
                            <flux:button wire:click="decidePlan('reject')" wire:target="decidePlan" wire:loading.attr="disabled" variant="danger" class="w-full">{{ __('Reject current plan') }}</flux:button>
                        @endif

                        @if ($needsPlanApprovalNote)
                            <flux:textarea wire:model.defer="planApprovalNote" :label="__('Plan decision notes (optional)')" rows="3" />
                        @endif

                        @if ($planBlockedNotice)
                            <flux:text class="text-xs text-amber-600">{{ __('You authored this story; the policy disallows self-approval for the current plan as well.') }}</flux:text>
                        @endif
                    </div>

                    @if ($hasStartExecution || $hasResume)
                        <div class="flex flex-col gap-2" data-section="execution-actions">
                            <flux:text class="text-xs font-medium text-zinc-500">{{ __('Execution') }}</flux:text>
                            @if ($hasStartExecution)
                                <flux:button wire:click="resumeExecution" wire:target="resumeExecution" wire:loading.attr="disabled" variant="primary" class="w-full">
                                    <span wire:loading.remove wire:target="resumeExecution">{{ __('Start execution') }}</span>
                                    <span wire:loading wire:target="resumeExecution">{{ __('Working…') }}</span>
                                </flux:button>
                            @endif
                            @if ($hasResume)
                                <flux:button wire:click="resumeExecution" wire:target="resumeExecution" wire:loading.attr="disabled" class="w-full">
                                    <span wire:loading.remove wire:target="resumeExecution">{{ __('Resume execution') }}</span>
                                    <span wire:loading wire:target="resumeExecution">{{ __('Working…') }}</span>
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($this->pendingPlanRun)
                    <flux:badge color="amber">{{ __('Generating plan…') }}</flux:badge>
                @endif
            </section>
        @endif

        @if ($rrCurrent->isNotEmpty() || $rrPrior->isNotEmpty())
            <section data-section="decision-log" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Story decision log') }}</flux:text>
                @forelse ($rrCurrent as $approval)
                    <x-decision-row :approval="$approval" />
                @empty
                    <flux:text class="text-xs text-zinc-500">{{ __('No story decisions on this revision yet.') }}</flux:text>
                @endforelse

                @if ($rrPrior->isNotEmpty())
                    <details class="mt-1">
                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Prior story revisions') }} ({{ $rrPrior->count() }})</summary>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($rrPrior as $approval)
                                <x-decision-row :approval="$approval" />
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endif

        @if ($planCurrent->isNotEmpty() || $planPrior->isNotEmpty())
            <section data-section="plan-decision-log" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Plan decision log') }}</flux:text>
                @forelse ($planCurrent as $approval)
                    <x-decision-row :approval="$approval" />
                @empty
                    <flux:text class="text-xs text-zinc-500">{{ __('No plan decisions on this revision yet.') }}</flux:text>
                @endforelse

                @if ($planPrior->isNotEmpty())
                    <details class="mt-1">
                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Prior plan revisions') }} ({{ $planPrior->count() }})</summary>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($planPrior as $approval)
                                <x-decision-row :approval="$approval" />
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endif

        @if ($rrEligible->isNotEmpty())
            <section data-section="eligible-approvers" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Eligible approvers') }}</flux:text>
                <ul class="text-sm">
                    @foreach ($rrEligible as $u)
                        <li class="py-0.5">{{ $u->name }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </aside>
@endif
