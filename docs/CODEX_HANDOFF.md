# Northwest ERP V2 — Codex handoff

## Read first
- `docs/northwest-v2-rebuild-plan.md` — migration and architecture proposal.
- `docs/v2-prototype/index.html` and `docs/v2-prototype/README.md` — current standalone prototype.
- Figma Make: https://www.figma.com/make/PMV0JkNEFgERVkhNI4tfdK/Company-Management-System
- Existing Laravel app and plugins, migrations, roles, and deployment config.
- Existing root `AGENTS.md` contains Laravel Boost instructions and potentially stale version details: verify installed versions against composer.lock before applying them. Do not overwrite those instructions without review.

## Goal and UI
Build a responsive Northwest ERP V2 for a Malaysian infrastructure/telecom business. Visual reference: current ERP first, Figma Make second. Use clean slate/white/blue, compact data tables, restrained icons, consistent spacing, minimal decoration; avoid generic AI-looking gradients and oversized cards. The Project Tracker is the highest-priority screen; mobile Daily Site Progress entry must be practical.

## Confirmed product rules
- Project dropdown in topbar; project-scoped tracker, progress, documents and project-linked finance; company-wide employee/payroll.
- Only authorized management can create projects or view all; other users see assigned projects. Remember selected project.
- Tracker one row per project + route + date, activity columns show **daily** recorded totals; underlying entries remain separately accessible for team/subcontractor, location and approval breakdown.
- All activities configurable per project, suitable for fiber, IBC and other workflows.
- Each activity has arbitrary user-named input fields, configurable datatypes, validation and optional calculation. **Do not hardcode CH, CH From, CH To or other field titles.** CH was only an example. Users can choose Start, End, Width, Quantity, or anything suitable.
- Column Settings is a **modal popup**, not browser prompt/confirm. Add/remove input rows; draft; validate/publish; test formula with sample values.
- Daily Progress dynamically renders active published fields and supports multiple entries per activity; quantities aggregate per route/date.
- Recorded progress remains visible regardless of approval; separate statuses and approved/claimable quantities. Claimable only after required verification/inspection/approval.
- Version published configurations. Formula changes require authorized impact preview and audited historical recalculation. Never silently alter issued financial documents.
- Other modules: projects, PO/invoice/claims, HR/payroll, approvals, documents, reports.

## Current state (as of 2026-11-03)
- Existing production ERP is Laravel/Aureus-based, on Railway. Production is not to be modified.
- Branch `planning/northwest-v2-rebuild`: migration plan; draft PR #11.
- Branch `feature/v2-ui-prototype`: static HTML/CSS/JS demo at `docs/v2-prototype/index.html`; draft PR #12.
- Prototype supports a basic sidebar, project switcher, route/date table, custom field popup, sample-value formula preview and demo entry. **It is not browser-tested or production-ready**; finance/HR/approvals are placeholders; no backend persistence.
- Figma Make is a React prototype with mock data, not a production API.

## Safety
- No deletion/reset of production data; no deployment, merge to master, DNS changes or paid provisioning without separate approval.
- Use isolated branches/environments and mock data for prototyping.
- Audit database schema before migration, then backup, rehearse, reconcile and plan rollback.
- Use a safe expression parser, not eval or Function. Enforce backend permissions and protect financial/payroll data.

## Codex workflow
1. Read this handoff and the linked files; inspect code and reconcile assumptions with actual repository versions.
2. Summarize confirmed vs uncertain requirements, then propose a small milestone.
3. Implement incrementally with tests and screenshots/browser validation where available.
4. Open a draft PR and report what was completed, tested and remains pending.
5. Do not claim a live deployment unless verified.

## First task
Audit the prototype for bugs and consistency with the user's popup screenshots. Then map legacy schema to V2 domain models, and propose implementation steps for project-specific activity configuration, safe formulas, and Daily Site Progress persistence.
