# Proposal 0002 — ADR-conformance review agent

Status: Draft
Date: 2026-05-01
Source: AI strategy audit, Bucket 3 #3 (scoped to one persona)

## Premise

ADR-0001 says StoryApproval gates the product contract and PlanApproval gates the current implementation plan, with no per-Task or per-Subtask approval gates. ADR-0002 says Tasks belong to Plans, not directly to Stories. ADR-0003 says the executor interface is pluggable. None of these rules are checked anywhere — they live in prose and depend on humans remembering them at PR review time. AI can read prose and code together at a price that makes per-PR enforcement viable.

This proposal carves a single, narrow Bucket-3 idea out of the broader "multi-persona review" concept: one reviewer, one job — flag PRs whose diffs contradict an accepted ADR.

## Decision (proposed)

Introduce a `ReviewProvider` interface mirroring `PullRequestProvider`. Implement one driver (`GithubReviewProvider`) and one persona (`AdrConformanceReviewer`). When `SubtaskRunPipeline` finishes opening a PR, it dispatches a `ReviewPullRequestJob` that runs the reviewer and posts review comments on the PR.

Specify becomes its own first customer.

## Concrete shape

### Interface

```php
// app/Services/Reviews/ReviewProvider.php
interface ReviewProvider
{
    /**
     * Post a review (with line-level comments if supported) on a PR.
     *
     * @param  list<ReviewComment>  $comments
     */
    public function postReview(Repo $repo, int $prNumber, string $summary, array $comments): void;
}

// app/Services/Reviews/ReviewComment.php — value object
final class ReviewComment {
    public function __construct(
        public string $path,
        public int $line,
        public string $body,
        public string $severity = 'warning', // info|warning|error
    ) {}
}
```

### Reviewer agent

`app/Ai/Agents/AdrConformanceReviewer.php`

- Inputs: list of accepted ADRs (markdown contents), the diff of the PR, the list of changed files.
- Tool: `ReadFile` (sandboxed to the working dir of the AgentRun).
- Output (structured): `{ overall: pass|warn|fail, summary: string, violations: list<{adr: string, file: string, line: int, reason: string}> }`.

The reviewer is given *one* job: "Find concrete contradictions between the diff and an accepted ADR. Cite ADR section. Quote the violated line." No general code review — that's a different persona, deliberately not in scope here.

### Pipeline hook

After `SubtaskRunPipeline::openPullRequest()` succeeds, queue `ReviewPullRequestJob` (non-fatal, like PR opening — ADR-0004 pattern). The job:

1. Loads accepted ADRs from `docs/adr/`.
2. Fetches the PR diff via the provider's API.
3. Runs `AdrConformanceReviewer`.
4. Posts the review with up to N comments (cap at 10 to avoid spam).
5. Records `review_url` / `review_error` on the AgentRun.

### Configuration

```php
'review' => [
    'enabled' => filter_var(env('SPECIFY_REVIEW_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'personas' => array_filter(explode(',', (string) env('SPECIFY_REVIEW_PERSONAS', 'adr-conformance'))),
],
```

Off by default; flip on per-environment.

## First experiment (one sprint)

1. Implement `ReviewProvider`, `GithubReviewProvider`, `AdrConformanceReviewer`, `ReviewPullRequestJob`.
2. Enable on this repo only (`SPECIFY_REVIEW_ENABLED=true` in dev).
3. Re-review the last 10 merged PRs offline (don't post; log only). Manually score: how many real ADR violations did it catch? How many false positives?
4. If precision ≥ 70%, enable for live PRs. If not, iterate the prompt — and *capture rejected reviewer comments as Bucket-3 #4 signal*.

## Failure modes and mitigations

- **Hallucinated ADR citations.** Mitigation: structured output requires an `adr` field that must match a filename under `docs/adr/`. Validate before posting.
- **Comment spam.** Mitigation: cap at 10 comments per PR, severity-sorted.
- **False positive on intentional ADR amendments.** Mitigation: if the PR diff *modifies* an ADR file, downgrade related violations to `info`. The reviewer is reading prose; let it tell us when it sees a deliberate update.
- **Reviewer becomes a gate by accident.** Mitigation: this is Bucket 1 if we ever block merge on a non-deterministic AI signal. Keep it advisory. Human is still the only approver.

## Reversibility

Additive: new interface, new job, new env flag. Disabling it sets `SPECIFY_REVIEW_ENABLED=false`. Removing it deletes one folder and one config block.

## Open questions

- Does the reviewer see *only* the diff or the surrounding file? (First version: diff only — keeps cost bounded. Add file context behind a flag if precision suffers.)
- Should reviewer comments survive in `AgentRun.output` for analysis, or live only on the PR? (Both — the PR is the human surface, the AgentRun is the analytics surface.)
- Per-ADR opt-out? (Add a frontmatter field `review.enforce: false` on ADRs that are advisory. Default: enforce.)
