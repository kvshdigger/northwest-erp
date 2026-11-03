# Northwest ERP V2 — Codex handoff

## Read first
- `docs/northwest-v2-rebuild-plan.md` — migration and architecture proposal.
- `docs/v2-prototype/index.html` and `docs/v2-prototype/README.md` — current standalone prototype.
- Figma Make: https://www.figma.com/make/PMV0JkNEFgERVkhNI4tfdK/Company-Management-System
- Existing Laravel app and plugins, migrations, roles, and deployment config.
- Existing root `AGENTS.md` contains Laravel Boost instructions and potentially stale version details: verify installed versions against composer.lock before applying them. Do not overwrite those instructions without review.

## Goal and UI
Build a responsive Northwest ERP V2 for a Malaysian infrastructure/telecom business. Visual reference: current ERP first, Figma Make second. Use clean slate/white/blue, compact data tables, restrained icons, consistent spacing, minimal decoration; avoid generic AI-looking gradients and oversized cards. The Project Tracker is the highest-priority screen; mobile Daily Site Progress entry must be practical.

Latest user direction: adopt the supplied clean, minimalist workspace screenshot as the visual style reference. Use white surfaces, thin borders, Inter typography, subtle indigo accents, consistent outline icons and project tabs. Keep Northwest's ERP navigation, compact tracker tables and mobile forms; the reference's chat/agent layout is not the ERP information architecture. The current prototype uses `theme.css` and local font/icon assets for this direction.

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

## Current state (as of 2026-11-04)
- Existing production ERP is Laravel/Aureus-based, on Railway. Production is not to be modified.
- Branch `planning/northwest-v2-rebuild`: migration plan; draft PR #11.
- Branch `feature/v2-ui-prototype`: static HTML/CSS/JS demo at `docs/v2-prototype/index.html`; draft PR #12.
- Prototype supports a basic sidebar, project switcher, route/date table, custom field popup, sample-value formula preview and demo entry. **It is not production-ready**; finance/HR/approvals are placeholders; no backend persistence.
- Figma Make is a React prototype with mock data, not a production API.

### Development continuation
- Draft PR #13 (`feature/v2-config-foundation`, targeting `feature/v2-ui-prototype`) repairs the prototype's JavaScript and adds separate in-memory drafts/published configurations, stable formula keys, typed input validation, safe arithmetic, per-entry snapshots, recorded/approved totals and modal details. It also carries the subsequent minimalist visual update.
- Domain validation: `node --test tests/Unit/ProgressConfiguration.test.mjs` (13 tests); the V2 Prototype Tests workflow passed for the initial configuration commit. Desktop/mobile browser checks cover the core demo flow. The static demo resets on refresh. The subsequent backend and connected workspace branches provide persistence; historical recalculation remains pending.
- Backend continuation branch `feature/v2-progress-persistence` is stacked on #13. The project-specific legacy migration now runs with the Projects plugin, after its project table exists; its migration name is preserved for existing installations.
- New opt-in V2 API persists project routes, memberships, activity drafts, immutable published versions, report inputs/results and audit events in separate tables. There is no legacy-data import or production migration in this milestone.
- Access is limited to assigned project managers/members and explicitly granted global permissions within allowed companies. Configuration editors and submission permissions are separate; membership management requires a manager or explicit management permission.
- Server-side formulas use bounded arithmetic parsing and exact decimal/rational calculation. Reports are atomic and idempotent; stale configuration versions and edit revisions are rejected. Tracker pagination preserves whole route/date groups and separates recorded/approved totals. Claimable remains null pending approval workflows.
- Enable `PROJECT_PROGRESS_V2_ENABLED=true` only in an isolated configured Laravel environment. Endpoints are under `/admin/api/v2/project-progress`; Sanctum authentication is required. Direct/static previews remain demo-only. The connected Laravel workspace described below uses session-authenticated endpoints with the same policies.
- Local verification: 126 Unit/ProjectFeature tests (382 assertions) passed against a private PostgreSQL test database; a new CI workflow covers MySQL and PostgreSQL. The connected branch completes that UI milestone; finance/HR/approvals remain placeholders.
- Connected continuation: `feature/v2-connected-workspace`, stacked on backend draft #14, serves `/admin/v2/project-progress` through the existing ERP login with CSRF-protected session requests. The approved UI now connects project selection, arbitrary titled activity drafts/publication, server calculation preview, mobile daily progress, tracker totals/pagination, filtered-page CSV and original entry details. Managers can add routes and manage project member capabilities. Demo mode is retained only for direct/static previews; no fallback to mock data occurs if live requests fail.
- Verification: 129 Unit/ProjectFeature tests (416 assertions), 24 Node tests and a real Chrome workflow passed locally against separate test/preview databases. Browser coverage includes draft/entry reload persistence, project isolation, lost-response retry without duplicates, CSRF rejection, read-only membership and mobile layout.
- Backend CI on #14 passed PostgreSQL but exposed a MySQL legacy index identifier that exceeded its limit. Commit `12c994f` shortens the creation-time index name and adds an installation assertion; already applied migrations retain their names and are not rerun. Fresh isolated PostgreSQL installation passed; remote MySQL rerun is pending.
- Still pending: approval/inspection workflows, audited historical recalculation and impact preview, financial safeguards, evidence uploads and broader module rebuilds.

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
