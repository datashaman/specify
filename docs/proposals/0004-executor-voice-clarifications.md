# Proposal 0004 — Give the executor a voice

Status: Draft
Date: 2026-05-01
Source: AI strategy audit, Identity check #2

## Premise

Today `SubtaskExecutor` has tools but no voice. Its instructions tell it: "If the Subtask is ambiguous, prefer the smallest interpretation that satisfies it." This trains the agent to *suppress* signal the system would benefit from hearing — exactly the article's "performing transformation while internally resisting reinvention."

The architecture treats the AI as a worker. This proposal converts it into a collaborator with the smallest possible change.

## Decision (proposed)

Add a `clarifications` field to `ExecutionResult` and to the executor's structured output schema. Surface clarifications back to the human as a `RequestStoryChanges`-style signal on the AgentRun. The executor can still execute — clarifications coexist with a successful diff. They are *information*, not a halt condition.

## Concrete shape

### Schema changes

```php
// app/Services/Executors/ExecutionResult.php
final class ExecutionResult
{
    /**
     * @param  list<string>             $filesChanged
     * @param  list<ExecutorClarification>  $clarifications
     */
    public function __construct(
        public string $summary,
        public array $filesChanged,
        public string $commitMessage,
        public array $clarifications = [],
    ) {}
}

// app/Services/Executors/ExecutorClarification.php
final class ExecutorClarification
{
    public function __construct(
        public string $kind,        // ambiguity|conflict|missing-context|disagreement
        public string $message,     // free text from the agent
        public ?string $proposed = null, // optional: what the agent did instead, or what it thinks should change
    ) {}
}
```

### Agent output schema

`SubtaskExecutor::schema()` adds:

```php
'clarifications' => $schema->array()
    ->items(
        $schema->object(fn ($schema) => [
            'kind' => $schema->string()
                ->enum(['ambiguity', 'conflict', 'missing-context', 'disagreement'])
                ->required(),
            'message' => $schema->string()->required(),
            'proposed' => $schema->string(),
        ])
    ),
```

### Instruction change

Replace:

> If the Subtask is ambiguous, prefer the smallest interpretation that satisfies it.

with:

> If the Subtask is ambiguous, conflicts with another part of the Story, or you would have chosen differently than what was specified, **execute the smallest reasonable interpretation AND record a clarification**. The clarification will be surfaced to the human reviewer alongside the diff. You are a collaborator, not a worker.

### Pipeline surfacing

`SubtaskRunPipeline` writes clarifications into `AgentRun.output['clarifications']` (already a JSON column). The PR body (Bucket-1 #3 fix) renders them as a `## Open questions` section, so the reviewer sees them in the natural place — next to the diff.

### Story-level signal (optional, V2)

If `clarifications` is non-empty *and* contains any `kind=conflict`, automatically flip the Story's status to `ChangesRequested` after the run completes. The rest of the run still succeeds (ADR-0004 pattern). For V1: surface them, don't auto-act on them.

## First experiment (one sprint)

1. V1: schema + clarifications field + PR body rendering. No automation, no auto-flips.
2. Watch 10 runs. Count: how many had clarifications? How many of those clarifications were *real signal* (caught a real ambiguity) vs noise (model overthinking)?
3. If real-signal rate ≥ 50%, ship V2 (auto-flip on conflicts).

## Failure modes and mitigations

- **The model produces noise to look thoughtful.** Mitigation: the kind enum is small and the prompt is firm — only four legitimate kinds. Reject runs with > 3 clarifications and log for prompt iteration.
- **Reviewers ignore the clarifications.** Mitigation: render them prominently in the PR body. If they're still ignored, that's the review process being broken, not the feature.
- **Clarifications drift into being a second approval gate.** Mitigation: ADR-0001 is the lodestone. Clarifications are *information*. The Story is still the only gate.

## Reversibility

Pure additive: optional field, optional output. Existing tests pass with no change. Removing it deletes one class and one schema entry.

## Open questions

- Should the clarification list be visible to the *next* Subtask's executor (via the context brief — Proposal 0003)? (Yes. Same Story, same context.)
- Should there be a "decline" kind where the executor can refuse a Subtask outright? (Not yet. That's a real halt and needs more design — propose separately if a refusal-rate signal warrants it.)
- Does this make sense for `CliExecutor` where the agent can't emit structured output? (Provide a sentinel: if the CLI's stdout contains `CLARIFY: <kind>: <message>` lines, parse them out. Otherwise, no clarifications. Not perfect, but consistent.)
