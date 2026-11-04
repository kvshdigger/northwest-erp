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
- UI follow-up on #15: simplified the connected toolbar into a compact status/refresh row and Project setup menu; collapsed navigation at narrower tablet widths; bounded/wrapped activity headers with sticky route/header cells and keyboard table scrolling; made modal actions sticky and locked background scroll; replaced unavailable/misleading overview cards with published-activity and loaded-page entry counts. In-app browser checks verified the user-configured calculation preview, setup popup, 390px layout bounds and reachable 44px Save/Publish controls. No settings or report records were saved during the UI audit.
- Business-module continuation: `feature/v2-business-modules`, stacked on #15, replaces Finance and Employees/HR placeholders with company-wide, permission-checked lists. Finance covers confirmed POs, invoices, bills and customer payments; HR covers work-only employee directory, departments and management leave requests. Search, pagination and permitted original ERP view/create/manage links are connected. Payroll, project finance mapping, budgets/claims, attendance and self-service remain explicitly planned. Synthetic examples and demo-account permissions were added only to the separate local preview; no production modules or accounts were changed.
- Business-module validation: 135-test Unit/ProjectFeature regression suite passed (446 assertions), followed by eight targeted module tests (36 assertions), 24 Node tests and a Chrome browser workflow. The browser workflow covers Finance tab/type separation, work-only employee search, existing employee-create form access and mobile width. Original ownership scopes are retained; synthetic invoice ownership was corrected only in the local preview to match its demo account.
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


### Versioned compensation and payslip template

User-confirmed policy: compensation profiles save immediately without approval. Saved and existing submitted/approved versions are eligible; the latest version covering the entire payroll period is selected. Earlier versions remain immutable. Payroll submission and independent approval remain required before issuing a payslip. New versions invalidate stale draft calculations.

The reusable `resources/views/payroll/payslip.blade.php` matches the supplied bordered payslip reference. Payroll managers can preview its clearly marked synthetic layout through Payroll & payslips → Preview payslip template. Issued payslips use stored payroll/header snapshots, masked identity numbers, separate employer contributions and authenticated existing access controls. Missing statutory identifiers, leave totals and unrecorded lateness/early-out values display a dash. Print using the browser's Save as PDF; the template uses A4 landscape. No remittance or payment is triggered.

Validation for this increment: 193 backend tests (783 assertions), 24 JavaScript tests, Pint and the Chrome automatic-payroll workflow passed. The sample PDF was rendered and visually checked as one A4 landscape page. Tests cover selecting the latest saved profile without approval, preserving earlier versions and frozen issued payslip details, and rejecting profile approval actions.


## Native V2 Finance continuation

`feature/v2-native-finance` replaces legacy Finance links with V2 lists, native create/edit dialogs, detail views and explicit confirmation for purchase orders, customer invoices, vendor bills and customer receipts. It reuses the existing purchasing/accounting models and calculation/posting services. PO drafts/RFQs are now included in the list; existing posted/confirmed records remain read-only. Item setup uses the existing product records with V2 labels changed to Item. New document lines use the item base unit. Shared items may be selected/viewed, but cannot be edited through a company-specific V2 form. Section/note lines on advanced existing documents are preserved by refusing an unsupported edit.

PO / Invoice configuration is company-specific: validated PNG/JPEG/WebP logo (512 KB maximum), display name/address, registration/tax identifiers, contacts, payment/bank details, document terms/footer, prefix/suffix, digits and next number. Number previews do not consume references; counters cannot move backwards. V2 numbering uses separate company sequences, serializes concurrent V2 creation and skips existing reference collisions; legacy sequence settings are unchanged. Counters continue across years; date tokens are available in prefixes/suffixes. Configure a distinct range when legacy and V2 entry run concurrently.

The new additive migration creates encrypted configuration storage and widens audit payload storage to LONGTEXT for validated logo snapshots on MySQL (PostgreSQL remains TEXT). Rollback does not narrow existing audit data. Configuration and saved document branding are audited; new V2 documents capture immutable branding snapshots, and print views use that captured version. Existing legacy documents without a V2 snapshot use current company branding. Printing uses the browser's Print / Save as PDF. Posting records accounting entries; it does not send emails, transfer funds or automatically allocate standalone customer receipts to invoices.

Configuration requires explicit `v2_finance_configure`. Existing purchase/accounting and item policies govern all record operations; selected/allowed companies and the V2 feature flag remain enforced. No production permission grants, production migration, deployment or merge occurred. Automatic approval review blocked enabling the additional configuration/item/finance-edit permissions for the synthetic local `v2-manager@example.test` account; activation awaits explicit user approval. Native Finance forms and mobile controls were checked with the account's existing create/view access; configuration API behavior is covered by isolated database tests.

Validation: 205 Unit/ProjectFeature tests (894 assertions), 24 JavaScript tests, Pint and Chrome native Finance/mobile checks passed locally. Twelve finance feature cases cover existing-model persistence/calculations/posting, safe retries/stale edits, branding snapshots, logo validation, numbering, permissions, company isolation, read-only posted records and rollback on audit failure. Browser check: `tests/13_native_finance/01_nativeFinance.spec.ts`; it uses existing demo permissions and does not grant new access. MySQL/PostgreSQL CI remains configured; local database tests used PostgreSQL.


## Work Orders, awarded routes and subcontract quantity control

`feature/v2-work-orders` adds Finance → Work Orders & margins. Each Work Order fixes its company, project, main contractor, currency and reference, with versioned routes, chainage ranges, item base units, awarded quantities, maincon unit rates and planned subcon rates. Drafts can be edited with revision checks; approval activates a complete immutable version. Variations create new versions, retaining prior snapshots and source line identities. Quantity reductions below active PO reservations, or changes to an existing source item's identity/unit/route, are rejected. The approval records a manager's confirmation of a received maincon award/variation; it is not an electronic contract-signing workflow.

New Project Tracker routes come exclusively from approved current Work Order versions. Standalone legacy routes are not offered for new reports. Existing reports remain visible, with no guessed Work Order backfill. Each new report explicitly selects a Work Order route/segment and validates start/end chainage against its approved range. Overlapping awards have separate selectable authorisations. Reports retain their original Work Order route/version and chainages after variations. Daily tracker aggregation remains by route/date; report details identify the originating Work Order. Contract chainage metadata is separate from user-configured activity inputs, titles and formulas.

For subcontract POs, select Source Work Order. Item options show their awarded route and remaining quantity. Server validation binds the current version, source item, company and currency; duplicate source lines are summed before checking the cap. Draft POs reserve quantities immediately, as do submitted/confirmed orders. Company/Work Order row locks serialize V2 allocation and variation approval. Draft edits exclude their own prior reservation, and cancellation of unconfirmed/pending-approval POs releases capacity. Confirmed orders cannot be cancelled through this new shortcut. Legacy model-level writes that would change or unlink awarded PO items are refused; use V2 for these records. General purchasing without a Work Order remains available for unrelated costs.

The margin view shows award value, reserved/committed subcon cost, available quantity and forecast gross margin including planned cost for unallocated quantities. It excludes other project costs, tax, overheads and actual revenue recognition; it does not present actual profit. Prices/rates are encrypted in Work Order version payloads and do not appear in tracker responses or subcontract PO printouts. Printed linked POs show their original source reference, version, route and chainage.

Commercial access reuses purchase read plus customer-invoice read permissions and assigned-project/selected-company access. Managing/approving additionally requires existing purchase create/update permissions and project progress configuration rights. No new permissions or production role grants were introduced. The previously approved local demo Finance/Item/configuration permissions were enabled for v2-manager@example.test.

Three additive migrations create Work Order storage and nullable links on existing POs/reports. Cross-plugin foreign keys are installed through Projects and Purchases migration manifests, preserving optional plugin installation order. All three were applied only to the isolated local preview. No production data deletion, migration, deployment, email, payment or merge occurred.

Validation: 215 Unit/ProjectFeature tests (999 assertions), 24 JavaScript tests, Pint and Chrome passed. Work Order tests cover routes, bounds, overlapping awards, immutable history, original report versions, aggregate quantity caps, variation reductions, stale drafts, cancellation releases, existing-model guards and commercial privacy. Chrome exercised Work Order/Item creation and approval → 600/1000 subcontract allocation → 400 available → authorised tracker selection, including mobile width. The existing configurable-progress/retry workflow also passed using an awarded route fixture. Tests: `tests/14_work_orders/01_workOrders.spec.ts` and `tests/07_project_progress/01_connectedWorkspace.spec.ts`. Local backend checks used PostgreSQL; MySQL/PostgreSQL CI remains configured.
