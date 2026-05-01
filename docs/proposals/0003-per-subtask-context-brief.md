# Proposal 0003 — Per-subtask context brief

Status: Draft
Date: 2026-05-01
Source: AI strategy audit, Bucket 3 #6

## Premise

`SubtaskExecutor::instructions()` is a static heredoc. Every Subtask, on every Story, in every Repo, gets the same system prompt. The article's "static artefact" trap, in code form. AI thrives on repo-aware context but the executor goes in blind beyond the prompt and the working directory.

## Decision (proposed)

Introduce a `ContextBuilder` callable invoked in `SubtaskRunPipeline::run()` *between* workdir checkout and `Executor::execute()`. It returns a `string $contextBlock` that the pipeline injects into the executor (via `Subtask::contextBrief` set transiently or a new parameter on `Executor::execute()`).

Start with the cheapest possible signal. Add layers only if they pay.

## Concrete shape

### Interface

```php
// app/Services/Context/ContextBuilder.php
interface ContextBuilder
{
    /**
     * Produce a per-subtask context brief, injected into the executor prompt.
     */
    public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string;
}
```

### Default implementation — `RecencyContextBuilder`

Three signals, all cheap:

1. **Files mentioned in the Subtask description.** Grep the description for path-like tokens; verify they exist; include a one-line `wc -l` of each.
2. **Files touched recently.** `git log --name-only --since=30.days --pretty=format:` in the working dir; intersect with the description-mentioned set; show the top 10 with their last-touched commit message.
3. **Prior runs on this same Subtask.** Read `AgentRun` rows where `runnable` is this Subtask and status is `Failed` or `NoDiff`; include their `summary` and `commit_message`.

Render as a markdown block prepended to the executor's prompt, marked with `<context-brief>...</context-brief>` tags so the executor knows where it ends.

### Pipeline integration

```php
// SubtaskRunPipeline::run()
$contextBrief = app(ContextBuilder::class)->build($subtask, $workingDir, $repo);
$result = $this->executor->execute($subtask, $workingDir, $repo, $branch, $contextBrief);
```

`Executor::execute()` gains a final `?string $contextBrief = null` parameter (default `null` keeps existing tests green). `LaravelAiExecutor` and `CliExecutor` both inject it before the existing prompt. `FakeExecutor` ignores it.

### Configuration

```php
'context' => [
    'builder' => env('SPECIFY_CONTEXT_BUILDER', 'recency'),
    'recency' => [
        'window' => env('SPECIFY_CONTEXT_WINDOW', '30.days'),
        'max_files' => (int) env('SPECIFY_CONTEXT_MAX_FILES', 10),
    ],
],
```

## First experiment (one sprint)

1. Implement `ContextBuilder` interface + `RecencyContextBuilder` + `NullContextBuilder` (returns empty string).
2. Add the `?string $contextBrief = null` parameter to the `Executor` interface; default null keeps every test passing.
3. Bind `RecencyContextBuilder` in production, `NullContextBuilder` in tests.
4. Pick 5 recent Subtasks, run them twice (once with brief, once without), compare:
   - Time-to-first-Edit-tool-call (proxy for "agent oriented faster").
   - Number of `ReadFile` calls before the first edit (proxy for thrashing).
   - Final diff cleanliness (does it touch unrelated files?).
5. If 3 of 5 improve, ship it.

## Failure modes and mitigations

- **Context bloat blowing prompt budget.** Mitigation: hard cap on brief size (4 KB). Truncate the lowest-value signal first (prior-run summaries before recency before mentions).
- **Wrong files highlighted.** Mitigation: false positives are recoverable — the executor can ignore the brief. The brief is a hint, not a constraint.
- **Builder becomes a bottleneck.** Mitigation: stays out of the hot path (per-subtask, not per-tool-call). Time-budget at 1 second; if it exceeds, fall back to `NullContextBuilder`.

## Reversibility

Pure additive: new interface + default param. Disabling is `SPECIFY_CONTEXT_BUILDER=null`. Removing is two file deletes.

## Open questions

- Should the brief be visible in the run audit log? (Yes — log it as a structured field on the AgentRun. It's part of what the executor saw.)
- Should the brief itself be AI-generated (a context-summarisation agent) rather than mechanical? (Not in V1. Mechanical first; if precision plateaus, layer an AI summariser on top.)
- Should failed-run summaries from *other* Subtasks in the same Story be included? (Yes — same Story implies shared context. Already cheap to query.)
