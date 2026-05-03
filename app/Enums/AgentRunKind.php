<?php

namespace App\Enums;

/**
 * What an AgentRun is for.
 *
 * `Execute` is the historical default — a Subtask AgentRun that produces a
 * diff for the Subtask's spec, commits, pushes, and opens a PR. The cascade
 * gate (`finalizeSubtaskFromRun`) decides Subtask status from terminal
 * states of `Execute` runs.
 *
 * `RespondToReview` is the webhook-driven flavour (ADR-0008): a Subtask is
 * already Done, its PR has open review comments, and a fresh AgentRun
 * dispatches the `ReviewResponder` agent to push a `fix(review):` commit on
 * the same branch. The cascade gate **ignores** RespondToReview runs —
 * review responses don't change Subtask status. The cap on cycles per PR
 * lives on the `repos` row.
 *
 * `ResolveConflicts` is a human-triggered merge-conflict repair on the
 * Story's primary PR: merge `origin/{default_branch}` with `--no-ff`, resolve
 * conflicts with AI, push one commit. The cascade gate ignores these runs
 * (same as RespondToReview).
 */
enum AgentRunKind: string
{
    case Execute = 'execute';
    case RespondToReview = 'respond_to_review';
    case ResolveConflicts = 'resolve_conflicts';
}
