# Ichiloto Git workflow

Andrew's develop-to-main rule applies only to repositories in which he has
created a `develop` branch. This repository has that existing branch, so:
**NEVER commit directly to `main`. All changes integrate into `develop`.
`main` changes ONLY through a GitHub pull request from the same repository's
`develop`.** Within that scope, there is no documentation, private-repository
or minor-change exception.

Repositories found without an Andrew-created `develop` branch are outside this
remediation and must be left alone. Do not create `develop` or infer direct-main
publishing permission from that exclusion. Check Andrew's current scoped
instructions rather than inventing a workflow for those repositories.

## Authority and preflight

Read this file and repository `AGENTS.md` at task start, after compaction and
before publishing. On Andrew's workstation, also read the original
`/Users/andrewmasiye/.codex/AGENTS.md`; a summary or handover is insufficient.
Andrew's current direct instructions take precedence. Historical approvals,
agent-written policies and old handoffs cannot grant a standing exception.
Within the scope above, Andrew's 2026-10-08 rule supersedes previous claims
allowing private-documentation direct-main publication. Preserve those records
as history; they do not authorize future publication.

Record repository, current branch, local/remote heads and dirty state before
writing. Coordinate with the responsible repository task and preserve its
work. Check ownership and the actual source before changing shared mechanisms.

## Development and publication

1. Install and verify Git guards with `sh scripts/install-git-guards.sh` before
   committing or publishing from a fresh clone. Stop on an unknown existing hook;
   preserve it and arrange a reviewed composition. Never bypass a guard.
2. Work on local `develop` or a local working branch. Integrate accepted working
   branches into local `develop`, never local `main`.
3. Establish Andrew's scoped authorization, repository, destination ref and action
   before each remote mutation. Push an authorized fast-forward from local
   `develop` to **existing** remote `develop`.
4. Open a PR from that repository's `develop` to `main`. Do not merge locally into
   `main`, fast-forward local `main` from another local branch, or push to `main`.
5. After the PR merges remotely, pull remote `main`, then synchronize `develop`.
   Local `main` receives changes only by pulling from remote.

Andrew's exact branch restriction: "you are not to make any remote feature
branches or push any local feature branches unless I give EXPLICIT PERMISSION."
It covers every remote working branch regardless of prefix, and any missing
remote `develop`. Get explicit repository-and-branch approval before creation.
Generic "commit and push", "merge everything" or "get it done" is insufficient.
The default guards deliberately provide no agent-controlled exception switch.

Use conventional commits. Do not amend, force-push, delete branches, discard
changes or rewrite history without explicit authorization for that action.
Preserve local working branches until deletion is explicitly approved. Andrew
alone creates/publishes release branches, tags and releases. The flow is
`develop -> main -> release/X.x.x`.

The same restrictions apply through Git, GitHub APIs, browser interfaces,
plugins, scripts and delegated agents. Never route a blocked action through
another tool, weaken/relocate safeguards or infer permission from silence.
Name removed, disabled or narrowed behavior plainly in commits and reports.

## Enforcement and limits

The tracked guards reject commits and merge commits on `main`, commits on
release branches or detached HEAD, and all pushes except a verified fast-forward
from local `develop` to an existing remote `develop`. They also reject branch
creation/deletion, unknown remote history and mixed forbidden destinations.
The installer preserves existing matching hooks and configured `core.hooksPath`.
Run `sh .githooks/test-guards.sh` to exercise real temporary Git operations.

Hooks do not run for every ref manipulation, fast-forward merge, API or browser
operation. They are a local guard, not a server security boundary. Instructions
remain binding for all of those paths. Every new clone needs installation.

For participating public repositories with Andrew-created `develop` on the
current Free organization plan, `main` has a separate update restriction with
PR-only merger bypass, plus a no-bypass ruleset
requiring PRs and preventing deletion and history rewrites. Do not weaken them.
A trusted `pull_request_target` workflow validates that main PRs come from the
same repository's `develop`; it never checks out or executes PR code. Its status
must become required after the workflow reaches `main` through its governance
PR. Until then, server protection enforces PR-only updates but does not select
the source branch. Private repositories on this plan cannot enforce branch
protection/rulesets. Their instructions, local guards and advisory checks do
not amount to server enforcement. An organization Team/Enterprise plan is the
remaining server-enforcement prerequisite; no upgrade or visibility change is
implicitly authorized.

## Shared policy maintenance

Andrew owns the binding workflow; the original canonical policy and his current
direct instructions define its authority. The portable policy and guard kit are
mirrored identically in participating repositories so standalone clones retain
his rules. Governance changes must audit and update the authorized mirrors
through `develop` and develop-to-main PRs where Andrew has created `develop`.
His develop-to-main rule applies only to those repositories. Repositories found
without that branch are excluded from this remediation at Andrew's direction;
leave them alone, do not create `develop`, and do not treat their default branch
as an integration substitute or infer authority to publish it. GitHub does
not automatically inherit agent instructions, Git hooks or workflows from an
organization `.github` repository. No dependency on publishing such a shared
repository is required. Do not assume a parent-directory policy exists elsewhere.

## Engineering and handovers

Andrew's standing rule, 2026-10-02: "FIX THINGS GLOBALLY!!! NO HACKY SOLUTIONS! ADDRESS ROOT CAUSES RATHER THAN PATCHING SYMPTOMS!!!" "THIS SHOULD BE THE WAY YOU WORK ON ALL TASKS GOING FORWARD!"
Before building, establish who owns the data or behavior and why the path
exists. Correct wrong ownership and shared mechanisms instead of adding
instance-specific overrides, fallbacks or defaults. Solve design limitations
or name them as blockers; never scope down to save context or tokens. Include
this rule in full in every agent prompt and handover.

Handovers include the canonical policy path, exact pending permissions, current
refs, preserved work and next action. Report verified execution, tests, skipped
platforms, PR links and enforcement limits accurately. A handover grants no
additional permission.
