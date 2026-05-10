<?php

namespace App\Mcp\Tools;

use App\Enums\ContextItemType;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\Context\ContextItemWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a text note or link asset to a project. Assets are shared reference material available to all stories in the project for AI plan generation.')]
class AddProjectAssetTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'add-project-asset';

    public function handle(Request $request, ContextItemWriter $writer): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'type' => ['required', 'string', 'in:text,link'],
                'title' => ['required', 'string', 'max:255'],
                'body' => ['required_if:type,text', 'nullable', 'string', 'max:10000'],
                'url' => ['required_if:type,link', 'nullable', 'string', 'url', 'max:2048'],
            ]);
        } catch (ValidationException $e) {
            return Response::error(implode(' ', array_merge(...array_values($e->errors()))));
        }

        $project = $this->resolveAccessibleProject($validated['project_id'], $user);
        if ($project instanceof Response) {
            return $project;
        }

        $type = ContextItemType::from($validated['type']);
        $metadata = $type === ContextItemType::Text
            ? ['body' => $validated['body'] ?? '']
            : ['url' => $validated['url'] ?? ''];

        try {
            $item = $writer->createProjectItem($project, [
                'type' => $type,
                'title' => $validated['title'],
                'metadata' => $metadata,
            ], $user);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'id' => $item->id,
            'project_id' => $item->project_id,
            'type' => $item->type->value,
            'title' => $item->title,
            'metadata' => $item->metadata,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project to attach the asset to.')->required(),
            'type' => $schema->string()->enum(['text', 'link'])->description('Asset type: "text" for a note, "link" for a URL.')->required(),
            'title' => $schema->string()->description('Asset title.')->required(),
            'body' => $schema->string()->description('Body text. Required when type is "text".'),
            'url' => $schema->string()->description('URL. Required when type is "link".'),
        ];
    }
}
