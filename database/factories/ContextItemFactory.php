<?php

namespace Database\Factories;

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use App\Models\ContextItem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContextItem>
 */
class ContextItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'story_id' => null,
            'type' => ContextItemType::Text,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'metadata' => ['body' => fake()->paragraph()],
            'summary' => null,
            'summary_status' => ContextItemSummaryStatus::Pending,
            'summary_error' => null,
            'created_by_id' => null,
        ];
    }

    public function forText(?string $body = null): self
    {
        return $this->state(fn () => [
            'type' => ContextItemType::Text,
            'metadata' => ['body' => $body ?? fake()->paragraph()],
        ]);
    }

    public function forLink(?string $url = null): self
    {
        return $this->state(fn () => [
            'type' => ContextItemType::Link,
            'metadata' => ['url' => $url ?? fake()->url()],
        ]);
    }

    public function forFile(?string $path = null, ?string $originalName = null, ?string $mime = null): self
    {
        return $this->state(fn () => [
            'type' => ContextItemType::File,
            'metadata' => [
                'disk' => 'private',
                'path' => $path ?? 'context/01HXXX/example.pdf',
                'original_name' => $originalName ?? 'example.pdf',
                'mime' => $mime ?? 'application/pdf',
                'size' => 1024,
            ],
        ]);
    }
}
