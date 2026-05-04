<nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-sm text-zinc-500" data-section="breadcrumb">
    <a href="{{ route('projects.show', $project->id) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $project->name }}</a>
    <span aria-hidden="true">›</span>
    <a href="{{ route('features.show', ['project' => $project->id, 'feature' => $story->feature_id]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $story->feature->name }}</a>
    <span aria-hidden="true">›</span>
    <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">{{ $story->name }}</span>
</nav>
