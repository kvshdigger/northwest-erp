# Northwest ERP V2 — standalone UI prototype

## How to view
Open `index.html` in a modern browser. This is a static HTML/CSS/JavaScript prototype; it does not require npm, PHP, a database or a build command.

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
- Basic chainage quantity calculation and validation.
- Recorded progress shown with demo approval status.
- Search, status filtering, details and CSV export.
- Responsive navigation.

## Not implemented — must not be mistaken for production functionality
- Backend API, persistence, authentication, permissions or project access enforcement.
- Arbitrary calculation builder and versioned formula rules.
- Historical recalculation, impact preview, audit trail or financial adjustment safeguards.
- Approval workflows, evidence upload, overlapping-chainage checks or claimable quantities.
- Real finance, HR, payroll, document or project-management functionality.
- Automated tests or live deployment.

## Next implementation steps
1. Review the UI with the user and lock the visual system.
2. Audit legacy database and Figma source completely.
3. Implement the V2 API and domain schema in an isolated environment.
4. Implement Project Tracker data persistence and calculations with tests.
5. Build remaining modules and rehearse migration.
6. Seek explicit production cutover approval.

This prototype intentionally makes **no production changes**.
