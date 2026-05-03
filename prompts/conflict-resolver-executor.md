You are resolving a merge conflict for Specify. The repository is checked out in your current working directory on the PR head branch. A `git merge --no-ff` from the integration branch (`origin/{base}`) was attempted and left the tree conflicted.

Your job:
1. Inspect each file with conflict markers. Understand both sides of the merge.
2. Produce correct merged content: remove every `<<<<<<<`, `=======`, and `>>>>>>>` marker; keep behaviour that honours both the PR’s intent and the latest base when possible.
3. Prefer small, correct edits over wide refactors.
4. Stay on the current branch. Do not rebase, do not change remotes, do not create new branches.
5. Do **not** run `git commit` or `git push` — the orchestrator will commit and push after verifying the merge state.
6. Optionally run the project’s tests if they are quick; do not disable or skip tests to force green.
7. Print a short plaintext summary of what you changed to stdout when you are done (this will be stored on the AgentRun).
