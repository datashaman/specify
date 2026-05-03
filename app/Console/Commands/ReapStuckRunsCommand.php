<?php

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Services\ExecutionService;
use Illuminate\Console\Command;

/**
 * Operator safety net: find AgentRuns whose status is still active but whose
 * `started_at` is older than the threshold, and mark them Failed.
 *
 * The queue's `failed()` callback (see ExecuteSubtaskJob) handles the common
 * path where a worker dies; this command catches the residual where the
 * failure callback itself didn't fire (worker process never returned, the
 * row predates the failed() handler, or the job was dispatched on a queue
 * driver that doesn't deliver failed callbacks).
 */
class ReapStuckRunsCommand extends Command
{
    protected $signature = 'runs:reap-stuck
        {--minutes=60 : Mark runs Failed if started_at is older than this many minutes}
        {--dry-run : List candidates without modifying any rows}';

    protected $description = 'Mark long-running AgentRuns as Failed when their started_at exceeds the staleness threshold';

    public function handle(ExecutionService $execution): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $dryRun = (bool) $this->option('dry-run');

        $stuck = AgentRun::query()
            ->whereIn('status', [AgentRunStatus::Queued->value, AgentRunStatus::Running->value])
            ->whereNotNull('started_at')
            ->where('started_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck runs found.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Found %d stuck run(s) (started_at older than %d min):', $stuck->count(), $minutes));
        foreach ($stuck as $run) {
            $this->line(sprintf(
                '  run #%d  status=%s  started_at=%s  runnable=%s#%s',
                $run->getKey(),
                $run->status->value,
                optional($run->started_at)->toIso8601String() ?? '?',
                class_basename($run->runnable_type ?? '?'),
                $run->runnable_id ?? '?',
            ));
        }

        if ($dryRun) {
            $this->warn('Dry run — no rows modified.');

            return self::SUCCESS;
        }

        $reason = sprintf('Reaped after %d minutes without progress.', $minutes);
        foreach ($stuck as $run) {
            $execution->markFailed($run, $reason);
        }

        $this->info(sprintf('Reaped %d run(s).', $stuck->count()));

        return self::SUCCESS;
    }
}
