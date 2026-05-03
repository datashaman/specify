<?php

use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Runs')] class extends Component {
    use WithPagination;

    public int $project_id;

    public ?string $status = null;

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        $this->project_id = (int) $project;
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function runs()
    {
        $projectIds = Auth::user()->scopedProjectIds();

        return AgentRun::query()
            ->where(function ($q) use ($projectIds) {
                $q->whereHasMorph('runnable', [Subtask::class], function ($qq) use ($projectIds) {
                    $qq->whereHas('task.story.feature', fn ($qqq) => $qqq->whereIn('project_id', $projectIds));
                })
                ->orWhereHasMorph('runnable', [Story::class], function ($qq) use ($projectIds) {
                    $qq->whereHas('feature', fn ($qqq) => $qqq->whereIn('project_id', $projectIds));
                });
            })
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->with('runnable.task.plan', 'runnable.task.story.feature.project', 'repo')
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Runs') }}</flux:heading>

    <div class="flex gap-2">
        <flux:select wire:model.live="status" :placeholder="__('All statuses')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            <flux:select.option value="queued">{{ __('Queued') }}</flux:select.option>
            <flux:select.option value="running">{{ __('Running') }}</flux:select.option>
            <flux:select.option value="succeeded">{{ __('Succeeded') }}</flux:select.option>
            <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
            <flux:select.option value="aborted">{{ __('Aborted') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->runs as $run)
            <x-run.summary-card :run="$run" />
        @empty
            <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->runs->links() }}
</div>
