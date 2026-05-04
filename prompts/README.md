# Prompts

Agent system-prompts that ship with Specify. Lifted out of `app/Ai/Agents/*.php`
so they participate in code review, are searchable, and can reference ADRs by
name.

| File | Loaded by | Purpose |
|------|-----------|---------|
| `subtask-executor.md` | `App\Ai\Agents\SubtaskExecutor::instructions()` | System prompt for the per-Subtask execution agent. |
| `tasks-generator.md`  | `App\Ai\Agents\TasksGenerator::instructions()`  | System prompt for the planning agent that drafts a current Plan from a Story. |

Both are loaded via `App\Services\Prompts\PromptLoader`, which caches contents
within a single PHP request. Edit the markdown directly — there is no build
step, and changes ship the next time the agent is constructed.

Per-subtask, repo-aware context (recent commits, prior runs, files relevant to
the Subtask description) is layered on top of these prompts at runtime by
`App\Services\Context\ContextBuilder`. Keep this directory for the
*invariant* part of each agent's instructions; do not encode per-run state
here.
