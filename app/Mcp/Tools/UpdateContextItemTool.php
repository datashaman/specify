<?php

namespace App\Mcp\Tools;

use App\Enums\ContextItemType;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\ContextItem;
use App\Services\Context\ContextItemWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update the title or body of an existing text or link context asset. File assets can only have their title changed.')]
class UpdateContextItemTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-context-item';

    public function handle(Request $request, ContextItemWriter $writer): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
        ]);

        $item = ContextItem::query()
            ->whereIn('project_id', $user->accessibleProjectIds())
            ->find($validated['id']);

        if (! $item) {
            return Response::error('Context item not found.');
        }

        $changes = [];
        if (isset($validated['title'])) {
            $changes['title'] = $validated['title'];
        }
        if (isset($validated['body']) && $item->type === ContextItemType::Text) {
            $changes['metadata'] = ['body' => $validated['body']];
        }

        if (empty($changes)) {
            return Response::error('Nothing to update. Provide title and/or body.');
        }

        try {
            $item = $writer->update($item, $changes, $user);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'id' => $item->id,
            'project_id' => $item->project_id,
            'story_id' => $item->story_id,
            'type' => $item->type->value,
            'title' => $item->title,
            'metadata' => $item->metadata,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Context item ID to update.')->required(),
            'title' => $schema->string()->description('New title.'),
            'body' => $schema->string()->description('New body text. Only applies to text-type assets.'),
        ];
    }
}
