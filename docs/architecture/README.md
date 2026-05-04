# Architecture

These pages explain Specify's current architecture. ADRs in `docs/adr/` remain the source of load-bearing decisions; architecture pages describe how those decisions are implemented in the codebase.

| Page | Purpose |
|---|---|
| [AgentRun lifecycle](agent-run-lifecycle.md) | Explains AgentRun kinds and statuses, execution scheduling, executor selection, workspace handling, PR/review follow-ups, progress events, retry/cancel semantics, and cascade rules. |
| [Approval architecture](approval-architecture.md) | Explains Story and current-Plan approval gates, policies, decision replay, auto-approval, reopening, and AgentRun authorisation. |
| [Project information architecture](project-information-architecture.md) | Explains Workspace, Team, Project, Feature, Repo, route, active-project, access-control, and MCP context rules. |
| [Story planning model](story-planning-model.md) | Explains the current Product contract -> Plan -> Task -> Subtask structure, approval gates, execution flow, Story page seams, and MCP terminology. |
