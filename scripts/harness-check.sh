#!/usr/bin/env bash
# scripts/harness-check.sh — project pass/fail gate.
# Called by ~/.claude/hooks/verify-before-stop.sh and `/verify`.
# Returns non-zero on the first failed sensor.
#
# Specify's canonical gate is `composer test`, which itself chains
# `pint --test` (lint) and `php artisan test` (the Pest suite).
# Wall-clock is ~40s for the full suite — over the suggested 10s
# budget, but it's the only check that actually verifies correctness
# and there's no faster proxy on this codebase.
set -uo pipefail

# When the parent shell's cwd has been deleted (e.g. by a prior
# WorkspaceRunner test that tore down a tempdir we happened to be inside)
# every subprocess inherits the broken cwd and PHP can't even resolve
# `composer`. Detect that and reposition to the project root before
# running anything. The script is invoked from .claude/hooks via
# $CLAUDE_PROJECT_DIR, so we always know where to land.
if ! pwd -P >/dev/null 2>&1; then
  cd "${CLAUDE_PROJECT_DIR:-$(dirname "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")")}" || exit 1
fi

# Disable Laravel Pao's pest output capture. Pao detects the agent
# environment and swaps verbose pest output for a single JSON summary
# line, which is great for protocol callers but useless here:
#   - Multi-failure runs produce a JSON line longer than the Bash tool's
#     output buffer and arrive truncated.
#   - Fatals during pest's *test collection phase* (e.g. an anonymous
#     class not satisfying a changed interface) abort before any result
#     exists, so Pao's shutdown handler emits nothing at all — exit=2
#     with zero output.
# Both modes drop us into a silent retry loop. Verbose pest output is
# what we actually want when the gate fails.
export PAO_DISABLE=1

step() {
  local label="$1"; shift
  echo "→ $label"
  if "$@"; then return 0; fi
  echo "❌ harness-check failed: $label" >&2
  exit 1
}

step "composer test" composer test

echo "✅ harness-check passed"
