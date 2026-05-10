<?php

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Assets')] class extends Component {
    public int $project_id;

    public function mount(int $project): void
    {
        $this->project_id = $project;
        abort_unless($this->project, 404);
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->find($this->project_id);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <livewire:pages::context-items.project-assets-panel
        :project-id="$this->project_id"
        :key="'project-assets-panel-'.$this->project_id"
    />
</div>
