# 0005. Plans grow append-only mid-run

Date: 2026-05-01
Status: Accepted

## Context

The original execution model treats the plan (a Story's task list) as a frozen artefact: TasksGenerator runs once on Story approval, the human approves, and the executor walks the plan in linear order without amending it. This is the pre-AI "PRD → tickets → execution queue" pattern, accelerated by AI but not reshaped by it — a "bad idea amplified" in the sense that AI makes the rot of frozen plans cheaper to produce, not better. Once a Subtask is running, the model has no way to course-correct beyond shoehorning extra work into the current Subtask or failing.

Two consequences fall out of the frozen-plan model:

- The executor cannot reflect or revise. If midway through a Subtask it discovers that the plan misses a step, the only options are to silently shoehorn the work into the current Subtask, fail the run, or trigger a full plan replacement that resets approval (ADR-0001) and halts the pipeline.
- The next Subtask never benefits from what the previous one learned beyond the diff that landed on the branch. Each call is one-shot.

We want the plan to behave as a *running hypothesis* the executor can extend during a run — without re-introducing per-Subtask AI gates (explicitly ruled out in `feedback_no_per_task_ai_gates`) and without contradicting "Story is the only approval gate" (ADR-0001).

## Decision

**Plans grow append-only mid-run. Append-only growth does not reset Story approval. Edits (changes to existing Subtasks) still reset, per ADR-0001.**

Concrete shape:

- `SubtaskExecutor` gains two structured-output fields alongside the existing `summary / files_changed / commit_message`:
  - `clarifications: list<{kind, message, proposed?}>` — voice channel for ambiguity, conflict, missing-context, or disagreement (Proposal 0004).
  - `proposed_subtasks: list<{name, description, reason}>` — follow-up engineering steps the executor judges necessary to complete the parent Task. Restricted to the parent Task only.
- A new `subtasks.proposed_by_run_id` nullable FK records which `AgentRun` appended a Subtask (NULL for human-authored or generator-authored Subtasks). This is the audit trail; nothing in the running pipeline reads it.
- `PlanWriter::appendProposedSubtasks(Task, list, AgentRun)` is the only seam that creates Subtasks via this path. It assigns positions after the current maximum, caps at three appended Subtasks per call (surplus discarded with a warning), and **does not** call `ApprovalService::recompute()`. The Story's revision is unchanged.
- After a successful run, `SubtaskRunPipeline` calls `appendProposedSubtasks` and dispatches the new Subtasks via `ExecutionService::dispatchSubtaskExecution`. Newly-appended Subtasks join the same authorising approval as the run that produced them.
- The PR body (`PrPayloadBuilder`) renders proposed subtasks under a "Proposed follow-up subtasks" section so the human reviewer sees the growth at merge time. The PR remains the diff-review surface (ADR-0001).

The carve-out from ADR-0001's "any subsequent edit resets approval" rule is principled:

- **Intent (the Story) is unchanged.** Append-only growth does not edit acceptance criteria, the story description, or any existing Subtask.
- **The plan grows, it does not change.** Existing approved Subtasks remain byte-for-byte the same.
- **The diff-review surface still works.** Each appended Subtask opens its own PR; the human catches unwanted growth there.
- **Without the carve-out, the mechanism is useless.** If every appended Subtask reset approval, the executor could never extend the plan within a single approved run — and the running-hypothesis loop collapses back to the frozen model.

Edit semantics are unchanged. `PlanWriter::replacePlan()` and `update-task` / `update-subtask` still reset approval per ADR-0001. The only new operation that *doesn't* reset is the executor-driven append.

## Consequences

Easier:

- The executor can now reflect on what it just did and amend the remainder of the plan within the same approved run. The next Subtask carries forward by virtue of running on the same branch with new context.
- Clarifications surface visibly in the PR body, replacing the old "if the Subtask is ambiguous, prefer the smallest interpretation" mute — the executor becomes a collaborator, not a worker.
- The audit log is richer: every executor-appended Subtask is traceable to the AgentRun that proposed it via `proposed_by_run_id`.

Harder / accepted trade-offs:

- ADR-0001's invariant ("any subsequent edit resets approval") is no longer absolute. The text of ADR-0001 is amended in place to reference this carve-out so the rule does not appear contradicted by code.
- A pathological executor could spawn unbounded follow-ups. Mitigation: a hard cap of 3 proposed subtasks per run in V1; runs exceeding the cap have the surplus discarded with a warning logged.
- `CliExecutor` cannot emit structured `clarifications` or `proposed_subtasks` in V1. Documented gap; CLI-driver runs have empty arrays. A sentinel-line parser is a future addition.
- Reviewers must scan the PR body for the proposed-follow-ups section to notice plan growth. Mitigation: the section is rendered prominently, and Bucket-3 #3 (ADR-conformance reviewer, Proposal 0002) is a natural follow-up that flags large or unexpected growth automatically.

Follow-ups:

- Capability flag on `Executor` for `supports_proposed_subtasks` so the pipeline skips the append step on drivers that always return empty arrays (covered by Bucket 2 #3 in the audit).
- Sentinel-line parser for `CliExecutor` to surface clarifications from CLI agents that print them as structured stdout lines.
- Optional Story-level cap on lifetime appended-subtask count; not in V1.
