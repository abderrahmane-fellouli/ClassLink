# Contributing

Use feature branches and reviewed pull requests to protected `main`. Link the
specification requirement or bug, state acceptance criteria, and report commands
actually run. Do not substitute historical test counts for current evidence.

## Ownership

- Backend owners edit application code, routes, migrations and backend tests.
- Frontend owners edit application components/screens and frontend tests.
- Infrastructure owners edit root deployment manifests, `.github/`, `scripts/`,
  configuration/environment examples, Vercel config and deployment documentation.
- Do not overwrite concurrent dirty changes. Existing audit/progress documents
  are historical records; add dated evidence rather than silently rewriting them.

## Required Checks

Run backend tests, frontend tests/build and infrastructure tests with clean
installs. CI also tests PostgreSQL, container readiness, queue/scheduler execution,
backup/restore and two browsers. Dependency audits are release gates. Security
advisories require remediation by the dependency owner; do not suppress them just
to get a green badge. Use maintained PHP 8.3 and Node 22.20+ for reproducibility.

Do not commit `.env`, secrets, dumps, bearer tokens, screenshots of real student
data or private logs. Use synthetic accounts and mocked provider calls in tests.
Frontend `VITE_*` values are public. Rotate leaked credentials; deleting a file
from the worktree does not remove its Git history.

## Delivery

Review migrations for old/new release compatibility, take a backup and verify
restorability before destructive operations. Configure platform integrations,
hooks, approval rules and alert recipients explicitly. A hook response is not a
successful deployment. Never claim live OAuth/mail/S3, restoration, browser
compatibility or 200-user performance without dated evidence. See
`docs/DEPLOYMENT.md` section 9 and `docs/INFRA_COMPLETION.md`.
