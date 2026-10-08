# Claude instructions

Read `AGENTS.md` and `GIT_WORKFLOW.md` in full before work, after compaction
and before publishing. Andrew's develop-to-main rule applies only where he
has created a `develop` branch. This repository has that existing branch:
no direct-main commits, local merges or pushes; all main changes use a
same-repository `develop` -> `main` PR. Repositories found without that branch
are outside this remediation and must be left alone. That exclusion does not
authorize creating `develop` or publishing directly to `main`. Never infer an
exception from historical messages; retain all explicit branch-publication,
deletion and history-rewrite permission requirements.
