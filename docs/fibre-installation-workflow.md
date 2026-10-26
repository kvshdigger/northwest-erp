# Northwest ERP — Fibre Installation Workflow

## Initial workflow decisions

- PM/IM creates daily progress entries for the assigned route section, subcontractor work package, activity/BOQ item, chainage, measured quantity, and required photo evidence.
- The assigned subcontractor PIC can confirm or dispute the PM/IM entry. The PIC cannot silently overwrite the original measurement.
- A dispute must include a reason and is resolved by an authorized PM/IM. Corrections must retain a history of the original value, revised value, actor, timestamp, and reason.
- Final progress approval belongs to an authorized PM/IM.
- A quantity becomes claimable only after the required inspection has passed and an authorized PM/IM has approved it.
- Photo evidence is required by default for every daily progress entry. Any exception must be documented and approved.
- Client BOQ rates and subcontractor rates are separate.
- Each route section may have multiple subcontractors. Each work package defines its own chainage range, assigned subcontractor, scope, schedule, and BOQ line allocations.

## Stage 1 data model

The existing projects table remains the project parent. The following tables extend the existing Projects plugin:

- projects_fibre_boq_items: client BOQ code, description, unit, contract quantity, and client rate.
- projects_fibre_route_sections: named route sections, location references, chainage range, access notes, and risks.
- projects_fibre_work_packages: subcontractor assignment to a project and route section, with its own chainage range and schedule.
- projects_fibre_work_package_items: BOQ lines and allocated quantities for each work package, including the agreed subcontractor rate.

A work package can contain multiple BOQ items, and a route section can have multiple work packages. This keeps the client contract rate separate from each subcontractor's agreed rate.

### Stage 1 management screens added

The Projects plugin now includes Filament management screens for Fibre BOQ, Fibre Route Sections, and Fibre Work Packages. Each work package has an Allocated BOQ Items relation manager for its quantities and subcontractor rates. BOQ allocation and route-section assignment are checked against the selected project, and the fibre planning records use the existing company-scope trait. These screens are a development foundation; they have not yet been run through the application or migration test suite.

## Chainage and quantity rules

- Store chainage as metres from the project-defined route origin, using decimal precision. The UI may display values such as KM 10+150.
- Validate that end chainage is greater than or equal to start chainage.
- Flag overlapping assignments for review; do not automatically reject legitimate overlapping activities when they are distinct work types.
- Do not let allocated quantities exceed the remaining BOQ quantity without an explicit authorized override.
- Do not count reported, PIC-confirmed, or disputed quantities as claimable.
- Claimable quantity must be based on accepted inspection and final authorized approval, with prior approved quantities and any reversals accounted for.

## Security and implementation notes

- The tables link to existing project, company, and partner records.
- Subcontractor portal permissions must be enforced server-side, not only by hiding UI elements.
- Daily progress, PIC responses, inspection decisions, and approval history require dedicated records and automated tests in the next stage.
- Before deployment, run the project's migration and test suites against a disposable development database. Do not run migrations on production data without a backup and explicit approval.


## Stage 2 — Daily progress capture

The development branch now includes a daily progress record and Filament entry screen. Each entry ties together the project, route section, assigned work package, allocated BOQ item/activity, work date, optional chainage range, reported quantity, notes, and private photo evidence.

- Photo evidence is required when an entry is submitted; draft records can be saved while being prepared.
- Submitted records are locked against editing in the model to preserve the original report. The table also hides edit/delete actions after submission.
- The entry is only a reported quantity. It is not yet PIC-confirmed, inspected, approved, claimable, or paid.
- The current screen does not provide PIC confirmation/dispute, inspection, final PM/IM approval, or approved exceptions to photo evidence. Those are separate workflow steps and must not be inferred from a submitted status.
- Evidence files use the private local disk. Production use requires confirming storage persistence, backups, and authorized retrieval before any subcontractor portal is enabled.

Stage 2 remains untested in a running Laravel/Filament environment. Run the migrations and UI tests against a disposable development database before merging.


## Stage 3 — PIC confirmation and dispute history

The development branch adds an append-only review event log in `projects_fibre_progress_reviews` and a read-only Filament history screen. The original submitted daily progress row remains unchanged.

- A PIC response is recorded as either `pic_confirmed` or `pic_disputed`. A dispute must include a reason and a proposed quantity.
- The first PIC response is recorded once per progress entry. The current model method prevents a second PIC response from being added to the same entry.
- An authorized PM/IM can resolve a dispute by accepting the original reported quantity, recording a corrected quantity, or rejecting the report. The resolution is a new event, with the actor, timestamp, quantity, and notes preserved.
- Review history records cannot be edited or deleted through the model. The progress entry and each review event remain separate.
- PIC confirmation is only agreement on the measurement; it is not inspection approval, final PM/IM approval, claimable quantity, or payment.
- The review log is currently read-only in Filament. The callable model workflow methods are foundations for a future authenticated PIC action and PM/IM resolution screen.

### Stage 3 limitations before use

The app still needs server-side authorization that verifies the logged-in PIC is assigned to the relevant subcontractor work package and verifies PM/IM approval permissions before calling the workflow methods. Do not expose these methods directly to an untrusted client. The event log does not yet create claimable quantities, handle inspection records, or calculate claims. Those belong in Stage 4 and Stage 5.

Stage 3 has not been run against the Laravel/Filament app or database. Validate the migration, authentication model/table conventions, company scoping, transaction/locking behavior, and automated tests in a disposable development environment before merging.


## Stage 4 — Inspection and final PM/IM approval

The development branch now adds `projects_fibre_progress_approvals`, an append-only event history for inspection outcomes and final PM/IM decisions, plus a read-only Filament history screen.

- Inspection is only recorded against a submitted entry with a PIC response or a resolved dispute. An unresolved dispute or rejected report blocks inspection.
- A passed inspection must record an accepted quantity greater than zero and no greater than the confirmed/resolved measurement quantity. A failed inspection requires a reason and records zero accepted quantity.
- Final PM/IM approval requires a passed inspection as the latest inspection/approval event and a non-rejected, confirmed/resolved report.
- Final approved quantity must be greater than zero and cannot exceed the latest inspected accepted quantity. The final decision is recorded as a new event; it does not modify the original daily progress report.
- Final approval is separate from PIC confirmation and inspection. A `pm_approved` event is the prerequisite for later claim processing, but the claim engine and paid quantities are not implemented in this stage.
- Inspection and approval history is append-only through the model and the Filament screen is read-only. The workflow methods are not yet connected to an operational inspection form or approval action.

### Stage 4 limitations before use

The caller must enforce server-side permissions for the assigned inspector and authorized PM/IM, and must verify required evidence—including photographs of concealed work before backfilling—before recording inspection. The current foundation does not independently verify that photos exist for a particular inspection, does not implement an approved photo-exception workflow, and does not calculate claimable balances. Stage 5 must calculate claims from final approved quantities while preventing duplicate claims and keeping subcontractor rates separate from client rates.

Stage 4 has not been run in the Laravel/Filament application. Validate migration order, authentication/table conventions, company scoping, and the workflow in a disposable development environment before merging.
