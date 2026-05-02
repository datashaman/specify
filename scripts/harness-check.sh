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

step() {
  local label="$1"; shift
  echo "→ $label"
  if "$@"; then return 0; fi
  echo "❌ harness-check failed: $label" >&2
  exit 1
}

step "composer test" composer test

echo "✅ harness-check passed"
