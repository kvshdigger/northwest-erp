# Northwest ERP V2 — prototype and connected workspace

## How to view
Open `index.html` in a modern browser, keeping `app.js`, `progress.js`, `theme.css` and the `assets/` directory alongside it. This is a static HTML/CSS/JavaScript prototype; it does not require npm, PHP, a database or a build command. A static development server can also serve this directory.

## UI design direction
- Follow the user's supplied minimalist workspace reference: white surfaces, thin dividers, Inter typography, restrained indigo actions, outline icons and underlined project tabs. Adapt the style to ERP tables and site-entry forms.
- Preserve the legacy ERP's practical sidebar/navigation and dense business tables.
- Refine spacing, typography, borders, buttons and hierarchy without decorative gradients or excessive dashboard cards.
- Use restrained slate, white and indigo; keep status colors subtle.
- Use Figma Make as a visual and interaction reference, not as a backend.
- Make the Daily Site Progress form usable on mobile.

`theme.css` contains the shared visual tokens and responsive styles. Icons are locally bundled [Tabler Icons](https://github.com/tabler/tabler-icons) v3.46.0 (MIT); the Inter Latin variable font is reused from the repository's Filament assets. License notices are in `assets/tabler-LICENSE` and `assets/inter-LICENSE`. The demo makes no runtime font or icon CDN requests.

## Implemented in this prototype
- Dashboard with demo project metrics.
- Project switcher between two sample projects.
- Project Tracker grouped by route and date.
- Activity columns scoped to the selected project.
- Activity visibility toggles and basic add-column interaction.
- Daily progress form with multiple entries per activity.
- Modal activity settings with arbitrary titles, stable formula keys, datatypes, required fields and numeric limits. No chainage field is mandatory.
- Safe arithmetic parser with sample-value preview, numeric output selection and text/date outputs.
- Separate configuration drafts and published versions **in memory only**. Draft saves leave the published setup available for new entries.
- Historical entry snapshots preserve configuration versions, inputs and calculated results; publishing affects new entries only.
- Per-entry team and location overrides; numeric daily totals and separate approved totals. Approval labels are demo data, not claim eligibility.
- Recorded progress shown with demo approval status.
- Search, status filtering, modal entry details and filtered CSV export with recorded/approved values and spreadsheet formula injection protection.
- Responsive navigation.
- Remembered project selection (browser storage permitting); demo records and configuration changes still reset on refresh.

## Not implemented — must not be mistaken for production functionality
- Backend API, persistence, authentication, permissions or project access enforcement.
- Server-side calculation validation, persisted formula versions and concurrent editing controls.
- Historical recalculation, impact preview, audit trail or financial adjustment safeguards.
- Approval workflows, evidence upload, overlapping-chainage checks or claimable quantities.
- Real finance, HR, payroll, document or project-management functionality.
- Live deployment.

## Validation

Run the dependency-free domain tests from the repository root:

```sh
node --test tests/Unit/ProgressConfiguration.test.mjs tests/Unit/ProgressApi.test.mjs
node --check docs/v2-prototype/progress.js
node --check docs/v2-prototype/app.js
```

These cover formula parsing, datatypes, limits, draft isolation, published versions, historical snapshots, project/route/date grouping and recorded/approved aggregation. They do not establish backend authorization or persistence. Browser validation is still required for each UI change.

## Backend continuation (separate from this static demo)

The `feature/v2-progress-persistence` branch adds an opt-in Laravel API under `/admin/api/v2/project-progress`. It persists project memberships/routes, draft and published activity configurations, daily entries and audit events. It validates calculations on the server and rejects stale revisions and duplicate submission keys with different payloads. No input title or chainage field is mandatory.

Enable `PROJECT_PROGRESS_V2_ENABLED=true` in an isolated Laravel environment after the normal ERP/Projects installation and migrations. Use Sanctum authentication. Endpoints include `GET projects`, `GET projects/{id}/workspace`, `POST projects/{id}/routes`, `PUT projects/{id}/members`, `POST projects/{id}/activities`, `PUT projects/{id}/activities/{activity}/draft`, `POST projects/{id}/activities/{activity}/publish`, `POST projects/{id}/activities/preview`, `POST projects/{id}/reports`, `GET projects/{id}/reports/{report}` and `GET projects/{id}/tracker`.

## Connected workspace

On `feature/v2-connected-workspace`, enable `PROJECT_PROGRESS_V2_ENABLED=true` in an isolated configured ERP environment, sign in through the existing ERP login and open `/admin/v2/project-progress`. A Project Tracker V2 link appears in ERP navigation when enabled. The Laravel-served page reuses these UI files, injects a same-origin API URL and CSRF token, and authenticates requests using the existing web session. No API token or password is stored by this client.

Project selection, activity drafts/publication, server formula preview, Daily Site Progress, tracker totals and original entry details now use persistent server data. Managers can add project routes and assign active company members with read, submit and configuration permissions. Read-only users cannot submit or configure. The selected project and tracker column visibility are optional browser preferences; records and configuration versions live in the database.

The tracker paginates complete route/date groups. Search/status filters, overview counts and CSV export apply to the currently loaded page, as stated in the workspace. A progress form retains its submission key when retried unchanged after a connection failure. Changed values start a new submission. Settings updates preserve the saved draft revision if publication fails. Refresh does not recalculate historical records.

Opening these files directly or serving them on a static server still runs the clearly labelled demo. It does not connect to the ERP. The connected route remains behind the feature flag and requires the Projects plugin and migrations. On `feature/v2-business-modules`, Finance and HR connect to permitted existing ERP records; approvals remain a placeholder; approval/claim eligibility, evidence uploads, historical recalculation and financial safeguards remain pending.

Browser verification uses the existing `tests/e2e-pw` tooling. Run only against an isolated environment with at least two assigned projects: set `PROJECT_PROGRESS_E2E=1`, `BASE_URL`, `PROJECT_PROGRESS_EMAIL` and `PROJECT_PROGRESS_PASSWORD`, then run `npx playwright test tests/07_project_progress/01_connectedWorkspace.spec.ts`. Optional member checks use `PROJECT_PROGRESS_MEMBER_NAME`, `PROJECT_PROGRESS_MEMBER_EMAIL` and `PROJECT_PROGRESS_MEMBER_PASSWORD`. The test creates routes/configurations/reports and changes that test member's access; use synthetic accounts. `PLAYWRIGHT_CHANNEL=chrome` can select installed Chrome. The test skips by default in the broad legacy suite.

Backend verification uses `php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,ProjectFeature --compact` against a dedicated disposable test database. Test bootstrap performs a fresh ERP installation; never point it at an operational database. The V2 Backend Tests workflow exercises MySQL and PostgreSQL.

This prototype intentionally makes **no production changes**.

## Finance and Employees/HR workspace

The business-module branch adds company-wide tabs for purchase orders, customer invoices, vendor bills, customer payments, employee directory, departments and leave requests. It requires the relevant existing plugins (`purchases`, `accounting`, `employees`, `time-off`) installed and active in the configured environment, plus the original module permissions. Missing and restricted modules show an explicit state. The API never grants permissions or installs modules.

The same session API prefix exposes `GET modules/{finance|people}` for capability metadata and `GET modules/{module}/{section}?search=...&page=...` for paginated records. Company and ownership restrictions are preserved; HR list responses exclude identity, bank, private contact and family information. Finance shows original amounts/currencies without combining balances. Since reliable legacy project links are not yet established, both areas are labelled company-wide and disable the project dropdown while selected.

Create, manage and open-record actions use the existing ERP forms and policies. Budget/claims, payroll, attendance and employee self-service remain labelled planned capabilities. The opt-in browser test `tests/08_business_workspace/01_modules.spec.ts` requires isolated synthetic `DEMO-*` business records and the same E2E login environment variables as the connected tracker test. It browses lists/search, opens the original employee form without submitting it, and checks mobile width.


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
