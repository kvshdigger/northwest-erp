# Northwest ERP V2 — standalone UI prototype

## How to view
Open `index.html` in a modern browser, keeping `app.js` and `progress.js` alongside it. This is a static HTML/CSS/JavaScript prototype; it does not require npm, PHP, a database or a build command. A static development server can also serve this directory.

## UI design direction
- Preserve the legacy ERP's practical sidebar/navigation and dense business tables.
- Refine spacing, typography, borders, buttons and hierarchy without decorative gradients or excessive dashboard cards.
- Use restrained slate, white and corporate blue.
- Use Figma Make as a visual and interaction reference, not as a backend.
- Make the Daily Site Progress form usable on mobile.

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

## Next implementation steps
1. Review the UI with the user and lock the visual system.
2. Audit legacy database and Figma source completely.
3. Implement the V2 API and domain schema in an isolated environment.
4. Implement Project Tracker data persistence and calculations with tests.
5. Build remaining modules and rehearse migration.
6. Seek explicit production cutover approval.

This prototype intentionally makes **no production changes**.
