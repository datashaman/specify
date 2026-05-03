<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-0011 HTTP poll endpoint: clients advance a per-run cursor to fetch
 * progress events written by `ProgressEmitter`. Reverb broadcast (Phase C)
 * layers over the same rows; the poll is the always-available fallback.
 */
class RunEventsController extends Controller
{
    public function __invoke(Request $request, AgentRun $run): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if (! $this->canSee($user, $run)) {
            abort(404);
        }

        $after = (int) $request->query('after', 0);
        $limit = max(1, min(500, (int) $request->query('limit', 200)));

        $events = AgentRunEvent::query()
            ->where('agent_run_id', $run->getKey())
            ->where('seq', '>', $after)
            ->orderBy('seq')
            ->limit($limit)
            ->get(['id', 'seq', 'phase', 'type', 'payload', 'ts']);

        return response()->json([
            'run_id' => $run->getKey(),
            'cursor' => $events->isEmpty() ? $after : (int) $events->last()->seq,
            'status' => $run->status->value,
            'events' => $events->map(fn (AgentRunEvent $e) => [
                'seq' => $e->seq,
                'phase' => $e->phase,
                'type' => $e->type,
                'payload' => $e->payload,
                'ts' => optional($e->ts)->toIso8601String(),
            ])->all(),
        ]);
    }

    private function canSee(User $user, AgentRun $run): bool
    {
        $accessible = $user->accessibleProjectIds();

        if ($run->runnable_type === Story::class) {
            $story = Story::with('feature')->find($run->runnable_id);

            return $story !== null
                && in_array($story->feature->project_id, $accessible, true);
        }

        if ($run->runnable_type === Subtask::class) {
            $subtask = Subtask::with('task.plan', 'task.story.feature')->find($run->runnable_id);

            return $subtask?->task?->story !== null
                && in_array($subtask->task->story->feature->project_id, $accessible, true);
        }

        return false;
    }
}
