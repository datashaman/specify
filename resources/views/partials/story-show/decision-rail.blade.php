@if ($showRail)
    <aside class="flex w-full flex-col gap-4 lg:w-80 lg:flex-none" data-rail-aside>
        @if ($decisionVisible)
            <section data-section="decision" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Decision') }}</flux:text>

                @if ($hasAnyDecisionAction)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($hasDraftSubmit)
                            <flux:button wire:click="submit" wire:target="submit" wire:loading.attr="disabled" variant="primary" class="w-full">
                                <span wire:loading.remove wire:target="submit">{{ $autoPromotes ? __('Submit & generate plan') : __('Submit for approval') }}</span>
                                <span wire:loading wire:target="submit">{{ __('Working…') }}</span>
                            </flux:button>
                        @endif

                        @if ($hasAutoStart)
                            <flux:button wire:click="startExecution" wire:target="startExecution" wire:loading.attr="disabled" variant="primary" class="w-full">
                                <span wire:loading.remove wire:target="startExecution">{{ __('Start execution') }}</span>
                                <span wire:loading wire:target="startExecution">{{ __('Working…') }}</span>
                            </flux:button>
                        @elseif ($hasApprovalActions)
                            @if ($userApproved)
                                <flux:button wire:click="decide('revoke')" wire:target="decide" wire:loading.attr="disabled" class="w-full">{{ __('Revoke approval') }}</flux:button>
                            @else
                                <flux:button wire:click="decide('approve')" wire:target="decide" wire:loading.attr="disabled" variant="primary" class="w-full">{{ __('Approve') }}</flux:button>
                            @endif
                            <flux:button wire:click="decide('changes_requested')" wire:target="decide" wire:loading.attr="disabled" class="w-full">{{ __('Request changes') }}</flux:button>
                            <flux:button wire:click="decide('reject')" wire:target="decide" wire:loading.attr="disabled" variant="danger" class="w-full">{{ __('Reject') }}</flux:button>
                        @endif

                        @if ($hasResume)
                            <flux:button wire:click="resumeExecution" wire:target="resumeExecution" wire:loading.attr="disabled" class="w-full">
                                <span wire:loading.remove wire:target="resumeExecution">{{ __('Resume execution') }}</span>
                                <span wire:loading wire:target="resumeExecution">{{ __('Working…') }}</span>
                            </flux:button>
                        @endif
                    </div>
                @endif

                @if ($needsApprovalNote)
                    <flux:textarea
                        wire:model.defer="approvalNote"
                        :label="__('Decision notes (optional)')"
                        rows="3"
                    />
                @endif

                @if ($blockedNotice)
                    <flux:text class="text-xs text-amber-600">
                        {{ __('You authored this story; the policy disallows self-approval.') }}
                    </flux:text>
                @endif

                @if ($this->pendingPlanRun)
                    <flux:badge color="amber">{{ __('Generating plan…') }}</flux:badge>
                @endif
            </section>
        @endif

        @if ($rrCurrent->isNotEmpty() || $rrPrior->isNotEmpty())
            <section data-section="decision-log" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Decision log') }}</flux:text>
                @forelse ($rrCurrent as $approval)
                    <x-decision-row :approval="$approval" />
                @empty
                    <flux:text class="text-xs text-zinc-500">{{ __('No decisions on this revision yet.') }}</flux:text>
                @endforelse

                @if ($rrPrior->isNotEmpty())
                    <details class="mt-1">
                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Prior revisions') }} ({{ $rrPrior->count() }})</summary>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($rrPrior as $approval)
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
