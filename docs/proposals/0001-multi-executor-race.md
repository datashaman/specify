# Proposal 0001 — Multi-executor race driver

Status: Implemented (see ADR-0006). The shape that landed differs from the original sketch: race fan-out lives in `ExecutionService` and produces sibling AgentRuns, not a wrapper executor.
Date: 2026-05-01
Source: AI strategy audit, Bucket 3 #1

## Premise

The `Executor` interface (ADR-0003) is genuinely pluggable, but every run binds to exactly one driver via `specify.executor.driver`. Pre-AI you would never propose "let three engineers solve the same ticket and pick one." With AI, the marginal cost of a parallel attempt is bounded and the human-in-the-loop already exists at the PR review surface. The question we cannot currently answer: *which executor produces the best diff for which shape of subtask?*

## Decision (proposed)

Add a meta-driver `multi` that fans `ExecuteSubtaskJob` across N configured drivers, runs each in its own working directory and on its own branch, and opens N PRs tagged with the executor that produced them. The reviewer picks one (merges it), declines the others (closes them). The choice is the data.

## Concrete shape

### Configuration

```php
// config/specify.php
'executor' => [
    'driver' => env('SPECIFY_EXECUTOR_DRIVER', 'laravel-ai'),
    'multi' => [
        'drivers' => array_filter(explode(',', (string) env('SPECIFY_EXECUTOR_MULTI', ''))),
        // Each entry must be a fully-resolvable driver name registered elsewhere
        // in this config block. e.g. SPECIFY_EXECUTOR_MULTI=laravel-ai,cli-claude,cli-codex
    ],
    'cli' => [ /* existing */ ],
],
```

### New executor

`app/Services/Executors/MultiExecutor.php`

- `needsWorkingDirectory(): true`
- `execute(...)`: dispatches `N` child `ExecuteSubtaskJob`s via `Bus::batch()`, one per child driver. Each child job receives the *same* `Subtask` but a derived `working_branch` (e.g. `specify/{feature-slug}/{story-slug}-by-{driver}`). Returns an `ExecutionResult` whose `summary` is "raced N executors; see batch {id}".
- The pipeline already opens one PR per `AgentRun`; multi creates one `AgentRun` per child driver, each with its own branch. The reviewer sees N PRs side-by-side.

### Capability flag (prerequisite — Bucket 2 #3)

Extend the `Executor` interface with:

```php
public function capabilities(): ExecutorCapabilities; // { streaming, multi-attempt, ... }
```

`MultiExecutor::capabilities()` declares `multi-attempt: true`. The pipeline checks this before deciding whether to dispatch one child run or fan out.

### Branch naming

Existing convention: `specify/{feature-slug}/{story-slug}`.
Race convention: append `-by-{driver-slug}`. Drivers register a slug (`laravel-ai`, `cli-claude`, `cli-codex`).

### PR titles

Existing: `'Specify: '.$subtask->name`.
Race: `'Specify [{driver-slug}]: '.$subtask->name` so the reviewer sees the source at a glance.

## First experiment (one sprint)

1. Add capability flags + `MultiExecutor` (no UI changes).
2. Configure two drivers (`laravel-ai` and `cli-claude`).
3. Run on the next 5 stories opted-in via a `story.use_multi_executor` boolean.
4. Tag the merged PR with `winner=<driver>`. After 5 stories, query: which driver wins, and on what kind of subtask?

## Failure modes and mitigations

- **Cost blow-up.** Two executor calls per subtask doubles AI spend. Mitigation: opt-in per story; cap multi-mode to N drivers; surface aggregate cost on the run.
- **Reviewer fatigue from N near-identical PRs.** Mitigation: a follow-up tool that posts a *diff-of-diffs* comment on the lead PR ("compared to PR #B, this one omits X, adds Y"). That *is* Bucket 3 #3 (multi-persona review) wearing a hat.
- **Branch noise on the host repo.** Closed-not-merged branches accumulate. Mitigation: pipeline deletes the losing branches after merge of the winner via a webhook handler.

## Reversibility

Pure additive: new driver name, new branch suffix, no schema changes. Removing it deletes one file and one config key.

## Open questions

- Do we open all N PRs immediately, or open only the winner after the model self-judges? (First version: open all N. Self-judging is a separate proposal.)
- Should the losers' AgentRuns be marked `Cancelled` or `Succeeded with no merge`? (First version: `Succeeded`; the merge state lives on the PR, not the run.)
- Should we record the *reviewer's* choice as a structured signal (e.g. `agent_run.race_outcome = won|lost|tied`) for later analysis? (Yes — minimal column, big payoff for Bucket 3 #4.)
