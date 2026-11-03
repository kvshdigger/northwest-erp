# Northwest ERP V2 — migration and rebuild plan

Status: planning and audit only. No production deployment or database mutation is authorized by this document.

## Source baselines (3 November 2026)

- Legacy repository: `kvshdigger/northwest-erp`, default branch `master`.
- Legacy application: Laravel 13 / PHP >=8.3 / Filament 5 / Livewire 4 / Vue 3, with Aureus ERP plugin packages.
- Existing `plugins/webkul/projects/src/ProjectServiceProvider.php` registers migrations for project stages, projects, milestones, tasks, task stages, task users, tags and favourites.
- `app/Providers/Filament/AdminPanelProvider.php` imports the `DailyProgressReportResource`.
- Figma Make: `https://www.figma.com/make/PMV0JkNEFgERVkhNI4tfdK/Company-Management-System`.
- Figma Make prototype: React + TypeScript + Vite, with `src/app/App.tsx`, `components/ProjectTrackerPage.tsx`, invoice, contact, company, approval, user, dashboard and activity-log screens. The app uses client-side page state; the tracker initializes from `data/mockData.ts`. Do not treat the prototype as a production backend.

## Architecture decision (provisional)

- Build a standalone V2 React/TypeScript frontend inspired by Figma Make.
- Retain Laravel as an API backend candidate until database schema and existing business logic are inventoried.
- Isolate V2 deployment, credentials and database from legacy production.
- A separate private V2 repository is preferred, but must be created through an authorized repository-creation workflow; until then, use this planning branch only.
- Do not copy legacy credentials or production database content into the new application.

## Functional scope

1. Company-wide employees, HR, payroll, roles and company settings.
2. Project-scoped project management, configurable route/location hierarchy, daily site progress, project documents and finance references.
3. Project dropdown remembers last selection; only assigned projects are accessible to ordinary users, while authorized management sees all.
4. Project Tracker: one row per project + route + progress date; configurable activity columns; all recorded progress visible with approval status; individual entries preserved.
5. Dynamic activity field builder: arbitrary input fields, typed values, units, validations, formulas, formula preview and draft/active states.
6. Activity entries: multiple chainage/quantity records per report and activity, per-team/subcontractor attribution, calculated daily totals, mobile-friendly entry.
7. Formula changes: impact preview, controlled recalculation of historical values, immutable before/after audit, explicit handling of missing inputs, and financial adjustment controls for already-issued claims/invoices.
8. Approval workflow: configurable verification and final authorization; only eligible approved/inspected quantities are claimable.
9. Finance: POs, invoices, claims and payment references; project-linked records without duplicating company-level master data.
10. Document references and evidence, including a future Google Drive integration.

## Migration phases and gates

### Phase 0 — audit
- Inventory legacy migrations, tables, models, dependencies, roles, custom fields, finance records and project-progress tables.
- Inspect all relevant Figma source components, navigation, interactions and mock data.
- Compare Figma features to confirmed business requirements; record gaps.
- Inventory production database **metadata** only after access is granted; do not assume its content matches the repository.
- Deliver schema mapping and a list of modules to reuse, adapt or replace.

### Phase 1 — foundation
- Create a separate V2 repository and development environment, with protected production branch.
- Build authentication, role/permission policies, project access scoping, audit trail, API conventions and CI.
- Implement responsive shell and project dropdown.

### Phase 2 — Project Tracker
- Define project activity/field/formula version schema.
- Implement safe expression evaluation (never eval arbitrary user expressions).
- Implement draft validation, activation, formula impact preview and audited recalculation jobs.
- Implement daily progress input, multi-entry aggregation by route/date and status breakdown.
- Test duplicate/overlapping chainage, permissions, mixed approval states and mobile workflows.

### Phase 3 — other modules
- Build project management, finance, HR/payroll, documents, dashboards, notifications and settings.
- Migrate compatible legacy business rules only after tests.

### Phase 4 — data migration rehearsal
- Take verified backup/snapshot; record row counts and checksums.
- Define mapping and stable external IDs for users, projects, partners, transactions and progress.
- Rehearse import in isolated environment; reconcile totals, permissions, audit history and document links.
- Record unsupported fields and manual remediation; never silently drop records.

### Phase 5 — controlled cutover
- Obtain explicit cutover approval.
- Freeze or synchronize writes as appropriate, perform final migration and reconcile.
- Run end-to-end acceptance tests and rollback rehearsal.
- Switch traffic only after acceptance; retain read-only legacy access and backups for agreed retention.

## Critical risks / decisions

- Existing legacy project and daily progress schemas require a complete inspection.
- Figma Make includes mock data and does not demonstrate persistence, authorization or server-side validation.
- Historical recalculation may affect approved quantities and issued financial documents; require versioning and an adjustment workflow.
- Payroll must be validated against applicable Malaysian statutory requirements.
- Production database credentials, backups, Railway services and DNS remain untouched.
- The Figma Make project currently shows company/product-owner concepts that must be reconciled with Northwest's single-company, multi-project workflow.

## Definition of done for planning

- File-level inventory of legacy models and migrations.
- Figma-to-feature matrix and prioritized gaps.
- Entity relationship / migration mapping.
- Architecture decision record.
- Risk, rollback, testing and cutover checklist.
- Approved V2 implementation backlog.

This file is a planning artifact, not a claim that the full audit or rebuild has been completed.

## Finance and HR scope — 4 November 2026

The first connected business-module milestone reuses existing ERP workflows rather than introducing a second accounting or employee database. Finance records currently have no audited project association suitable for the V2 tracker; the initial lists therefore remain explicitly company-wide. Project membership does not grant Finance or HR access.

| Area | Required capability | Current V2 milestone |
| --- | --- | --- |
| Purchasing | Supplier orders, quotations, approvals, delivery and bill references | Confirmed purchase-order list and permitted existing ERP record/create/manage forms; quotation and approval handling stays in the original ERP |
| Receivables | Customer invoices, due amounts, receipts and ageing | Separate invoice/customer-payment lists with original currency amounts and existing ERP actions |
| Payables | Vendor bills, approvals and settlement references | Vendor-bill list and existing ERP actions; broader payment reporting remains pending |
| Project finance | Budgets, cost allocation, variations, retention and progress claims | Pending explicit project mapping and inspection/approval eligibility; never infer claimable amounts from recorded progress |
| Expenses/reporting | Receipts, reimbursements, cash flow, reconciliation and exports | Pending V2 workflows; no combined totals across currencies |
| Employee administration | Directory, work contacts, departments and employment status | Company-scoped directory and departments with permitted original ERP forms; private identity/bank/family fields excluded from the V2 list API |
| Leave | Requests, dates, duration, balances and approvals | Management request list and original ERP workflow; dedicated self-service/balance views pending |
| Attendance | Site assignment, attendance, timesheets and approved overtime | Pending |
| Payroll | Earnings, deductions, versioned statutory settings, review, payslips and payment references | Pending rule configuration and payroll validation; no hardcoded rates or automatic payouts |
| Employee lifecycle | Contracts, permits, document expiry, onboarding/offboarding and private self-service | Pending |

All lists require the installed active module and existing list/record permissions, preserve current company/ownership scopes and additionally restrict companies to those allowed for the user. Read actions are paginated and searchable. Business record edits use the original ERP forms so their existing policies and validation remain authoritative. Unavailable and restricted modules are visible as clear states, with no fabricated records or permission escalation.
