<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Http\Resources\ContextItemResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectContextItemController extends Controller
{
    public function __invoke(Request $request, Project $project): AnonymousResourceCollection
    {
        abort_unless(
            in_array($request->user()->roleInTeam($project->team_id), TeamRole::cases(), true),
            403,
        );

        return ContextItemResource::collection(
            $project->contextItems()
                ->orderBy('id')
                ->get(),
        );
    }
}
