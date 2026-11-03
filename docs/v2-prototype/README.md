# Northwest ERP V2 — standalone UI prototype

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
node --test tests/Unit/ProgressConfiguration.test.mjs
node --check docs/v2-prototype/progress.js
node --check docs/v2-prototype/app.js
```

These cover formula parsing, datatypes, limits, draft isolation, published versions, historical snapshots, project/route/date grouping and recorded/approved aggregation. They do not establish backend authorization or persistence. Browser validation is still required for each UI change.

## Backend continuation (separate from this static demo)

The `feature/v2-progress-persistence` branch adds an opt-in Laravel API under `/admin/api/v2/project-progress`. It persists project memberships/routes, draft and published activity configurations, daily entries and audit events. It validates calculations on the server and rejects stale revisions and duplicate submission keys with different payloads. No input title or chainage field is mandatory.

Enable `PROJECT_PROGRESS_V2_ENABLED=true` in an isolated Laravel environment after the normal ERP/Projects installation and migrations. Use Sanctum authentication. Endpoints include `GET projects`, `GET projects/{id}/workspace`, `POST projects/{id}/routes`, `PUT projects/{id}/members`, `POST projects/{id}/activities`, `PUT projects/{id}/activities/{activity}/draft`, `POST projects/{id}/activities/{activity}/publish`, `POST projects/{id}/activities/preview`, `POST projects/{id}/reports`, `GET projects/{id}/reports/{report}` and `GET projects/{id}/tracker`.

The static files above **do not call this API**. The next milestone is to connect the approved UI, including project selection, modal configuration editing, mobile daily entries and tracker details. Approval workflows, claim eligibility, evidence uploads, historical recalculation and financial safeguards remain pending.

Backend verification uses `php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,ProjectFeature --compact` against a dedicated disposable test database. Test bootstrap performs a fresh ERP installation; never point it at an operational database. The V2 Backend Tests workflow exercises MySQL and PostgreSQL.

This prototype intentionally makes **no production changes**.
