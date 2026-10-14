Read `AGENTS.md` and `GIT_WORKFLOW.md` before acting. Andrew's develop-to-main
rule applies only where he has created a `develop` branch. This repository has
that existing branch: no direct commits, local merges or pushes to `main`.
All changes integrate into `develop` and reach `main` only through a
same-repository develop-to-main PR. Repositories found without Andrew-created
`develop` are outside this remediation and must be left alone; neither creating
that branch nor direct-main publishing is authorized by that exclusion.
Keep explicit branch-publication, deletion and history-rewrite permissions.
No historical private-repository exception grants permission.
