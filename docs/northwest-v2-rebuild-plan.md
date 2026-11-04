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


## Native V2 employee and department milestone

`feature/v2-native-hr` continues the business-module branch with employee profiles, create/edit forms and department create/edit forms in responsive V2 popups. It reuses the installed Employee/Department models, policies and contact synchronization. Forms cover work, organisation, personal and employment-document reference fields; uploads, bank details, account linking, payroll and company transfers are outside this milestone. Private profile fields require the existing record-view permission and are not added to directory responses.

Writes validate company-scoped references, reject hierarchy cycles, detect stale edits and preserve a create request key for safe retries. An encrypted, append-only `v2_business_events` audit receipt is saved in the same transaction; the new root migration must be rehearsed before any operational rollout. Employee update ownership now resolves the coach's linked user rather than comparing employee IDs to user IDs, including existing group scope. No global permission grants are required.

Finance and leave still use existing ERP forms; native leave approvals and native Finance workflows remain pending. The opt-in `tests/09_native_hr/01_nativeHr.spec.ts` creates and edits synthetic records, checks reload persistence and mobile layout; use an isolated preview only. Validation: 150 backend tests (517 assertions), 24 JavaScript tests, and the native HR Chrome workflow passed locally. No production migration, deployment or merge was performed.


## Native V2 leave request increment

`feature/v2-native-leave` adds native V2 leave profiles, create/edit popups and reasoned approve/refuse actions. It uses the installed leave tables and duration helper, validates employee/company/type references, rejects overlaps and checks approved allocations covering the requested dates. Pending requests reserve allocation; editing excludes the original request. Employee locks serialize V2 request/decision writes, revisions reject stale actions and encrypted business events record changes atomically. Existing legacy writers do not participate in V2 locking.

Manager-only, HR-only and two-stage approval settings are enforced, with distinct reviewers for two stages and no self-approval. Types explicitly configured without validation are automatically approved. HR reviewers are configured leave-type officers or users with existing global resource scope and update permission; reviewers need a company employee profile. The leave update policy now checks linked users, reporting managers and assigned officers rather than unrelated Employee/User primary keys. No new global scope is granted.

This increment supports whole and half working days using the legacy weekend calculation. Hourly requests, required attachments, public-holiday duration and negative-balance configurations explicitly require the existing ERP workflow. Allocation checks are conservative: allocations must cover the whole request and non-refused leave within their coverage window reserves days. Accrual/expiry reconciliation and the dedicated balance dashboard remain pending, as do native Finance forms. No operational data reset, production migration, merge or deployment was performed.

Validation: the final 162-test backend regression suite (573 assertions), 24 JavaScript tests, PHP Pint and the native leave Chrome workflow passed. The 12 leave-specific tests cover both approval stages, refusal, self-approval, half-days, ownership, allocations, retries and stale edits. A Chrome workflow created/edited a synthetic request, checked reload persistence and mobile width. Browser test: `tests/10_native_leave/01_nativeLeave.spec.ts`, opt-in with the existing isolated-preview E2E environment variables.


## Operational HR workspace continuation

`feature/v2-hr-workflows` replaces the four planned HR cards with native V2 workspaces. Attendance records clock-in/out, IANA time zone, unpaid breaks, project/site assignment, requested overtime and independent approval. Durations are calculated on the server; overlapping or future/uncompleted shifts are rejected. These HR timesheets are separate from legacy task analytic records to avoid duplicate project billing or payroll posting.

Payroll has versioned, independently approved configurations, exact minor-unit calculations for fixed and percentage-of-base components, verified manual components, draft/submission/review and restricted printable payslips. Malaysia is the default configurable country profile with EPF, SOCSO, EIS, PCB/MTD, optional LINDUNG 24 Jam/SKBBK and employer components. These are manual statutory entries by default, not a certified automatic Malaysian statutory engine. Applicable amounts must be verified using current official employee/wage-band schedules. Configuration snapshots and employee/company names are preserved on each payroll record. No bank payment, statutory remittance, EA filing or accounting posting occurs.

Documents use authenticated private downloads with a 10 MB PDF/image/DOCX upload limit, expiry/review dates and due/expired filters. Replacements are new records preserving history. User-defined onboarding/offboarding checklists track item completion/reopening and due dates; completing a checklist does not automatically deactivate accounts. Expenses retain private receipts, company-currency amounts and independent review; approval does not reimburse funds automatically.

Self-service uses the active employee linked to the logged-in user: own contact/emergency updates with revisions, conservative current approved leave allocations/reservations, own leave submissions, attendance, private documents, expenses and approved payslips. Identity/company cannot be supplied to impersonate another employee in self-service leave requests. HR accounts without project assignments can use company modules independently of tracker access. Only approved payroll appears in employee self-service.

New `v2_hr_manage_{attendance,payroll,documents,expenses}` and corresponding `v2_hr_approve_*` permissions are explicit and company-scoped; both manage and approve are required for review. They are available in the role editor's custom permission tab. No production role grants are made. An independent reviewer cannot approve their own record or payslip. Creators can withdraw unapproved records and submit corrected records; approved data remains immutable through these APIs. New generic HR records and audit payloads are encrypted; audit failures roll back writes and clean up uploaded files. Existing permissions, allowed companies, active selection and the V2 feature flag remain enforced.

The new root migration was applied only to the isolated local preview. Synthetic manager/reviewer permissions retained individual resource scope. Final regression: 178 backend tests (664 assertions), 24 JavaScript tests, Pint and a Chrome workflow passed. Chrome covered attendance, private permit upload, checklist completion, separate configuration/payroll review, issued payslip links, HR access without projects, contact persistence and 390px layout. Run `tests/11_hr_workflows/01_hrWorkflows.spec.ts` only with isolated synthetic records and the existing E2E environment variables; optional `PROJECT_PROGRESS_REVIEWER_EMAIL` selects the independent reviewer.

Still outside this increment: certified automatic statutory schedules/PCB calculation and filing, automatic bank/accounting integration, automatic legacy timesheet migration, accrual reconciliation and native Finance forms. No production deployment, data reset or merge was performed.


## Automatic Malaysia monthly payroll

`feature/v2-auto-malaysia-payroll` connects Employee profile → Salary & payroll profile to versioned, independently approved compensation records. Monthly basic salary, titled recurring allowances, wage/tax classifications, employment dates, DOB, citizenship, SOCSO/EIS/SKBBK eligibility, tax family/residency details, verified TP1 reliefs and opening YTD are configured once. Monthly gross is derived from salary plus allowances. Private payroll setup remains restricted to payroll managers; employee self-service cannot modify compensation or fetch another employee's setup defaults.

The normal New payroll draft popup now asks only for company, employee, period and pay date, followed by Calculate preview and Save calculated draft. It uses actual calendar days in the wage month under Employment Act section 18A, including employment boundaries and approved unpaid working days/half-days. For 19–31 October, the numerator is 13 and denominator 31. Allowances are prorated individually when their approved contract classification says so; each component rounds to sen. Unresolved unpaid leave and overtime are blocked rather than omitted.

The versioned 2026 Malaysia pack includes official SOCSO/SKBBK and EIS bands/caps, EPF ordinary category bands/rounding, and standard resident/non-resident PCB. Resident PCB uses prior taxable remuneration, qualifying EPF, category/child/disability/approved TP1 deductions, prior PCB/zakat, the remaining months and upward five-sen rounding. Official HASiL worked normal-remuneration examples are covered by tests. Approved auto payroll carries its YTD forward; gaps, unclassified manual history and duplicate wage/tax months require reconciliation. EPF/SOCSO/EIS taxable bases follow each allowance's explicitly verified classification; exemptions and limits must be correctly split/configured by HR, never inferred from a title.

Preview hashes bind compensation, government rule pack, leave and tax history. Draft creation is idempotent; submission/approval recalculate and reject changed inputs. Payslips retain original compensation/rule/calculation snapshots and remain subject to independent approval. No historical salary recalculation, financial posting, remittance or payment happens automatically.

Scope is ordinary full-time monthly MYR employment in Peninsular Malaysia/Labuan, as confirmed by the user, using the verified 2026 pack. Calendar-month periods and one consolidated run per wage/tax month are required. Special tax regimes, legacy EPF categories, statutory age-transition months, EPF wages over RM20,000 (rounding not yet verified here), hourly unpaid leave, mid-period compensation changes and overtime needing ordinary/rest-day/public-holiday classification stop for specialist processing. This implementation is not government-certified payroll software; future-year packs, exceptional remuneration, tax clearance/filing and automatic statutory remittance remain outside this increment. The existing specialist/manual workflow is retained separately.

Validation: 192 Unit/ProjectFeature tests (750 assertions), 24 JavaScript tests, Pint and Chrome passed locally. Fourteen automatic-payroll cases cover calendar denominators, approved unpaid leave, official contribution bounds/caps/categories, HASiL worked PCB examples, YTD carry-forward, changed previews, independent issue, eligibility history and privacy. The browser workflow creates a synthetic employee/compensation version, uses a separate reviewer, calculates the 13/31 example without manual salary fields and verifies mobile controls and persistence. Test: `tests/12_automatic_payroll/01_automaticPayroll.spec.ts`; existing isolated-preview E2E variables apply.

Official references: Employment Act 1955 section 18A (JTKSM); EPF Third Schedule effective October 2025 (KWSP); PERKESO 2025 booklet Act 800 tables and updated SKBBK FAQ schedules; HASiL 2026 computerized MTD specification, including its unchanged normal-remuneration worked examples. Pack URLs are in `config/malaysia-payroll.php`; any formula/table change must bump the pack version. The isolated synthetic preview company uses MYR. No production currency, payroll records, migrations, permissions or deployment were changed, and nothing was merged.
