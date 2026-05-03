<?php

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan')] class extends Component {
    public int $project_id;

    public int $plan_id;

    public function mount(int $project, int $plan): void
    {
        $this->project_id = (int) $project;
        $this->plan_id = (int) $plan;

        $loaded = $this->plan;
        abort_unless($loaded, 404);

        $user = Auth::user();
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function plan(): ?Plan
    {
        return Plan::query()
            ->whereHas('story.feature', fn ($q) => $q
                ->where('project_id', $this->project_id)
                ->whereIn('project_id', Auth::user()->accessibleProjectIds())
            )
            ->with([
                'story.feature.project',
                'story.acceptanceCriteria',
                'story.scenarios.acceptanceCriterion',
                'approvals.approver',
                'tasks.story.feature.project',
                'tasks.acceptanceCriterion',
                'tasks.scenario',
                'tasks.dependencies',
                'tasks.subtasks.agentRuns.repo',
            ])
            ->find($this->plan_id);
    }
}; ?>

<div class="flex p-6">
    @if (! $this->plan)
        <flux:text class="text-zinc-500">{{ __('Plan not found.') }}</flux:text>
    @else
        @php
            $plan = $this->plan;
            $story = $plan->story;
            $feature = $story->feature;
            $project = $feature->project;
            $rail = match ($plan->status) {
                PlanStatus::Approved, PlanStatus::Done => 'approved',
                PlanStatus::PendingApproval => 'pending',
                PlanStatus::Rejected => 'rejected',
                PlanStatus::Superseded => 'changes_requested',
                default => 'draft',
            };
            $tasks = $plan->tasks->sortBy('position')->values();
            $tasksByAc = $tasks->groupBy('acceptance_criterion_id');
            $unmappedTasks = $tasksByAc->get(null, collect())->sortBy('position')->values();
            $acs = $story->acceptanceCriteria->sortBy('position')->values();
            $subtaskCount = $tasks->reduce(fn ($acc, $task) => $acc + $task->subtasks->count(), 0);
            $effective = [];
            foreach ($plan->approvals->where('plan_revision', $plan->revision ?? 1)->sortBy('created_at') as $approval) {
                $key = (int) $approval->approver_id;
                if ($approval->decision === \App\Enums\ApprovalDecision::Approve) {
                    $effective[$key] = $approval;
                } elseif ($approval->decision === \App\Enums\ApprovalDecision::Revoke) {
                    unset($effective[$key]);
                }
            }
            $policy = $plan->effectivePolicy();
            $latestRun = $tasks->flatMap->subtasks->flatMap->agentRuns->sortByDesc('id')->first();
            $branch = $latestRun?->working_branch;
            $repo = $latestRun?->repo;
        @endphp

        <x-rail :state="$rail" class="mr-4" />

        <div class="flex min-w-0 max-w-5xl flex-1 flex-col gap-6">
            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-sm text-zinc-500" data-section="breadcrumb">
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $project->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('plans.index', ['project' => $project->id]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ __('Plans') }}</a>
                <span aria-hidden="true">›</span>
                <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">{{ $plan->name ?? __('Plan') }}</span>
            </nav>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" data-section="plan-header">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <x-state-pill :state="$rail" :label="$plan->status->value" />
                            <flux:badge>{{ __('v') }}{{ $plan->version }}</flux:badge>
                            <flux:badge>{{ __('rev') }} {{ $plan->revision }}</flux:badge>
                            @if ((int) $story->current_plan_id === (int) $plan->id)
                                <flux:badge variant="solid">{{ __('current plan') }}</flux:badge>
                            @endif
                            <flux:badge>{{ count($effective) }}/{{ $policy->required_approvals }} {{ __('approvals') }}</flux:badge>
                            <flux:badge>{{ $tasks->count() }} {{ __('tasks') }} · {{ $subtaskCount }} {{ __('subtasks') }}</flux:badge>
                        </div>
                        <flux:heading size="xl" class="mt-2">{{ $plan->name ?? __('Plan') }}</flux:heading>
                        <flux:text class="mt-1 text-sm text-zinc-500">{{ $feature->name }} · <a href="{{ route('stories.show', ['project' => $project->id, 'story' => $story->id]) }}" wire:navigate class="underline">{{ $story->name }}</a></flux:text>
                    </div>

                    <a href="{{ route('stories.show', ['project' => $project->id, 'story' => $story->id]) }}" wire:navigate>
                        <flux:button>{{ __('Open story contract') }}</flux:button>
                    </a>
                </div>

                @if ($plan->summary)
                    <x-markdown :content="$plan->summary" />
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    @if ($plan->design_notes)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Design notes') }}</flux:text>
                            <x-markdown :content="$plan->design_notes" class="mt-2" />
                        </div>
                    @endif
                    @if ($plan->implementation_notes)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Implementation notes') }}</flux:text>
                            <x-markdown :content="$plan->implementation_notes" class="mt-2" />
                        </div>
                    @endif
                    @if ($plan->risks)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Risks') }}</flux:text>
                            <x-markdown :content="$plan->risks" class="mt-2" />
                        </div>
                    @endif
                    @if ($plan->assumptions)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Assumptions') }}</flux:text>
                            <x-markdown :content="$plan->assumptions" class="mt-2" />
                        </div>
                    @endif
                </div>
            </section>

            <section class="flex flex-col gap-3" data-section="plan-body">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <flux:heading size="lg">{{ __('Tasks') }}</flux:heading>
                    <flux:text class="text-xs text-zinc-500">
                        {{ $acs->count() }} {{ __('ACs') }} · {{ $subtaskCount }} {{ __('subtasks') }}
                        @if ($repo)
                            · {{ $repo->name }}
                        @endif
                    </flux:text>
                    @if ($branch)
                        <flux:text class="font-mono text-xs text-zinc-400 truncate max-w-[24rem]" :title="$branch">{{ $branch }}</flux:text>
                    @endif
                </div>

                @forelse ($acs as $ac)
                    @php $acTasks = $tasksByAc->get($ac->id, collect())->sortBy('position'); @endphp
                    <flux:card data-ac="{{ $loop->iteration }}" data-ac-id="{{ $ac->id }}">
                        <details open>
                            <summary class="flex cursor-pointer list-none flex-wrap items-baseline gap-2 text-sm [&::-webkit-details-marker]:hidden">
                                <span class="text-zinc-400" aria-hidden="true">▾</span>
                                <flux:badge size="sm">AC{{ $loop->iteration }}</flux:badge>
                                <span class="font-medium">{{ $ac->statement }}</span>
                            </summary>

                            @if ($acTasks->isEmpty())
                                <flux:text class="mt-2 text-xs text-zinc-500">{{ __('No task mapped to this AC in this plan.') }}</flux:text>
                            @else
                                @foreach ($acTasks as $task)
                                    @include('partials.story-task', ['task' => $task])
                                @endforeach
                            @endif
                        </details>
                    </flux:card>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No acceptance criteria on the parent story.') }}</flux:text>
                @endforelse

                @if ($unmappedTasks->isNotEmpty())
                    <flux:card data-ac="unmapped">
                        <details open>
                            <summary class="cursor-pointer text-sm font-medium">{{ __('Unmapped tasks') }} ({{ $unmappedTasks->count() }})</summary>
                            @foreach ($unmappedTasks as $task)
                                @include('partials.story-task', ['task' => $task])
                            @endforeach
                        </details>
                    </flux:card>
                @endif
            </section>
        </div>
    @endif
</div>
