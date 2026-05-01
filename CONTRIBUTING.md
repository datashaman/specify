# Contributing to Specify

Thanks for your interest. This is a young project; conventions below may shift, but the orientation here is current as of the latest commit.

Before anything else, read the [README](README.md) for what Specify is and the [ADRs](docs/adr/) for the load-bearing decisions. New contributions that conflict with an ADR need a follow-up ADR, not a workaround.

## Development setup

```bash
composer setup            # install deps, copy .env, key:generate, migrate, npm build
composer dev              # serve + queue + pail + vite, all at once
composer test             # pint --test + php artisan test
```

Requires PHP `^8.4`, Node, and SQLite by default.

## Running the test suite

```bash
php artisan test --compact                    # full suite
php artisan test --compact --filter=Approval  # filter by name
```

Run the minimum number of tests needed during development. The full suite runs in CI.

Pest is the test framework (Pest 4). Most tests should be feature tests; create with `php artisan make:test --pest {name}`.

## Code style

```bash
vendor/bin/pint --dirty --format agent
```

Pint configuration lives in `pint.json`. Run `--dirty` to format only files you've changed; CI runs `--test` and will fail on style drift.

PHPDoc conventions:
- Class-level docblock on every public class.
- Public method docblocks where the name and types don't already say it all.
- Use `@throws` only when the caller realistically needs to handle the exception.
- Skip docblocks on migrations and one-line accessors.

## Commit messages and PRs

- One logical change per commit. The first line is a short summary (`<area>: <imperative>`). Use the body for the *why*.
- PR titles follow the same shape. PR descriptions should reference the Story or ADR they implement.
- Open as draft if you want early feedback. Mark ready-for-review only when CI is green.

## What needs an ADR

Add an ADR under `docs/adr/` (use `0000-template.md`) when you change:
- An approval gate (who, when, threshold).
- A model that other services depend on (especially the `Story → Task → Subtask` chain).
- The `Executor` interface or its lifecycle.
- How runs are dispatched, branched, or pushed.

Refactors and bug fixes don't need ADRs.

## Reporting bugs / proposing features

Open a GitHub issue with a reproduction (for bugs) or a user-facing description (for features). For features, frame the request as a Story — "as a {role}, I {want}, so that {outcome}" — so the planning surface stays consistent with the in-app model.
