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
