# Northwest ERP — Fibre Claims (Stage 5)

## Claim basis

- Subcontractor claims are calculated only from final PM/IM-approved quantities recorded in `projects_fibre_progress_approvals` as `pm_approved`.
- Claim amounts use the subcontractor rate snapshot from the work package's BOQ allocation. The client BOQ rate is not used to calculate subcontractor claims.
- Claim line quantities, rate snapshots, and amounts are retained for audit. Payment records are append-only and track quantity paid separately from amount paid.
- Claim workflow statuses are draft, submitted, reviewed, approved, partially paid, paid, and rejected.
- Draft and rejected claims do not reserve claimable quantities. Submitted, reviewed, approved, partially paid, and paid claims reserve quantities to prevent reuse.
- Payments can only be recorded against approved or partially paid claims, and cumulative paid quantity for a claim line must not exceed the quantity claimed.

## Important boundaries

This stage adds the claim data model, workflow methods, and a read-only claims list foundation. The workflow methods are not yet wired to create/edit/submit/approve/payment forms. Callers must enforce server-side role and company/subcontractor assignment permissions before invoking them.

The current claimable quantity calculation sums final approval events for a work-package BOQ allocation. It depends on the existing workflow's one-final-decision-per-progress-entry rule. Reversals, retention, tax, deductions, client invoicing, and bank reconciliation are not implemented. Payment amounts are recorded as entered and are not inferred from paid quantity.

Before use, verify claim quantity reservation under concurrent transactions, migration order and foreign-key conventions, company scoping, the project's Filament version, and the correct application user model/table. Run migration and automated tests against a disposable development database. This code has not been runtime-tested and must not be treated as production-ready.
