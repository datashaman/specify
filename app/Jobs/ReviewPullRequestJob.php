<?php

namespace App\Jobs;

use App\Ai\Agents\AdrConformanceReviewer;
use App\Models\AgentRun;
use App\Services\Reviews\ReviewComment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts an advisory ADR-conformance review on a freshly-opened PR.
 *
 * Non-fatal by design (same posture as ADR-0004 for PR creation): any
 * failure logs and records `review_error` on the AgentRun output, never
 * mutates run status. The Story is the only approval gate (ADR-0001);
 * this job adds *signal*, not gates.
 */
class ReviewPullRequestJob implements ShouldQueue
{
    use Queueable;

    public const COMMENT_CAP = 10;

    public function __construct(public int $agentRunId) {}

    public function handle(): void
    {
        $run = AgentRun::find($this->agentRunId);
        if ($run === null) {
            return;
        }

        $output = (array) $run->output;
        $repo = $run->repo;
        $prNumber = $output['pull_request_number'] ?? null;
        $diff = (string) ($run->diff ?? '');

        if ($repo === null || $prNumber === null || $diff === '') {
            return;
        }

        $provider = $repo->reviewProvider();
        if ($provider === null) {
            return;
        }

        try {
            $adrs = $this->loadAdrs();
            if ($adrs === []) {
                return;
            }

            $files = array_values(array_filter(array_map('strval', $output['files_changed'] ?? [])));
            $reviewer = new AdrConformanceReviewer($adrs, $this->clamp($diff, 32_768), $files);
            $response = $reviewer->prompt($reviewer->buildPrompt())->toArray();

            $violations = is_array($response['violations'] ?? null) ? $response['violations'] : [];
            $comments = $this->violationsToComments($violations);
            $summary = trim((string) ($response['summary'] ?? ''));
            if ($summary === '') {
                $summary = 'ADR-conformance review found no signal to report.';
            }

            $result = $provider->postReview(
                repo: $repo,
                pullRequestNumber: $prNumber,
                summary: '## ADR-conformance review (advisory)'."\n\n".$summary,
                comments: $comments,
            );

            $run->forceFill([
                'output' => array_merge((array) $run->output, [
                    'review_url' => $result['url'],
                    'review_comment_count' => count($comments),
                    'review_overall' => $response['overall'] ?? null,
                ]),
            ])->save();

            Log::info('specify.review.posted', [
                'run_id' => $run->getKey(),
                'pr_number' => $prNumber,
                'comments' => count($comments),
                'overall' => $response['overall'] ?? null,
            ]);
        } catch (Throwable $e) {
            $run->forceFill([
                'output' => array_merge((array) $run->output, [
                    'review_error' => $e->getMessage(),
                ]),
            ])->save();
            Log::warning('specify.review.failed', [
                'run_id' => $run->getKey(),
                'pr_number' => $prNumber,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load all accepted ADRs as filename => markdown content.
     *
     * @return array<string, string>
     */
    private function loadAdrs(): array
    {
        $dir = base_path('docs/adr');
        if (! File::isDirectory($dir)) {
            return [];
        }

        $files = File::files($dir);
        $out = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'md' || str_starts_with($file->getFilename(), '0000-')) {
                continue;
            }
            $contents = trim($file->getContents());
            if (! str_contains($contents, 'Status: Accepted')) {
                continue;
            }
            $out[$file->getFilename()] = $contents;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return list<ReviewComment>
     */
    private function violationsToComments(array $violations): array
    {
        // Normalise severity *before* sorting so unknown values rank the
        // same way they will be rendered (warning), and the cap-at-10 keeps
        // the comments that actually get posted, not the ones that
        // happened to claim a higher severity.
        $normalised = array_map(function (array $v): array {
            $v['severity'] = in_array($v['severity'] ?? '', ReviewComment::SEVERITIES, true)
                ? (string) $v['severity']
                : ReviewComment::SEVERITY_WARNING;

            return $v;
        }, $violations);

        usort($normalised, fn ($a, $b) => $this->severityRank($b['severity'])
            <=> $this->severityRank($a['severity']));

        $normalised = array_slice($normalised, 0, self::COMMENT_CAP);
        $out = [];
        foreach ($normalised as $v) {
            $body = sprintf(
                "**ADR violation** (`%s`)\n\n%s",
                $v['adr'] ?? '?',
                trim((string) ($v['reason'] ?? '')),
            );
            $line = isset($v['line']) ? (int) $v['line'] : null;
            $out[] = new ReviewComment(
                body: $body,
                path: isset($v['file']) ? (string) $v['file'] : null,
                line: $line !== null && $line > 0 ? $line : null,
                severity: $v['severity'],
            );
        }

        return $out;
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            ReviewComment::SEVERITY_ERROR => 3,
            ReviewComment::SEVERITY_WARNING => 2,
            ReviewComment::SEVERITY_INFO => 1,
            default => 0,
        };
    }

    private function clamp(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit)."\n[diff truncated]";
    }
}
