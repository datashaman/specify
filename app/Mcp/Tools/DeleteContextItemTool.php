<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\ContextItem;
use App\Services\Context\ContextItemWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a context asset by ID. Story-scoped deletions reopen the story for approval.')]
class DeleteContextItemTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'delete-context-item';

    public function handle(Request $request, ContextItemWriter $writer): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $item = ContextItem::query()
            ->whereIn('project_id', $user->accessibleProjectIds())
            ->find($validated['id']);

        if (! $item) {
            return Response::error('Context item not found.');
        }

        $writer->delete($item, $user);

        return Response::json(['deleted' => true, 'id' => $validated['id']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Context item ID to delete.')->required(),
        ];
    }
}
