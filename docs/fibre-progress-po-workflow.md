# Northwest ERP — Progress-Based Fibre PO Workflow

## Intended process

1. The subcontractor submits measured progress and supporting evidence.
2. The assigned IM/PM verifies the report, handles any PIC dispute, records inspection, and makes the final PM/IM approval.
3. An authorized internal user generates a PO review record from final-approved progress that has not already been allocated to another PO.
4. The system snapshots the approved quantity and the work package's subcontractor rate, then calculates each line amount as quantity × subcontractor rate.
5. An internal reviewer checks the work package, BOQ allocation, quantities, rates, amount, and supporting progress before approving the PO for release.
6. An authorized user releases the PO. Only then is the PO reference assigned and the record locked.
7. The PO register retains the linked progress approval IDs to support traceability and duplicate prevention.

## Rules

- Unsubmitted, disputed, inspected-only, or not-final-approved progress cannot be included.
- Each final PM/IM approval can be allocated to one PO line only. The database unique constraint provides a final duplicate guard.
- Client BOQ rates are not copied into PO lines. The line amount uses a snapshot of the assigned subcontractor rate.
- Released PO records are immutable. Corrections or scope changes should be recorded as a separate variation/correction workflow rather than silently editing the released record.
- Returning a PO for correction does not release it. The current first iteration marks the record rejected; a corrected PO should be generated after the underlying progress/allocation issue is resolved.
- This PO workflow does not change or merge the original Aureus repository.

## Implementation boundary

The current implementation adds a Northwest ERP fibre progress PO register and a review/release workflow in the Projects plugin. It is a controlled, auditable PO record, but it is **not yet connected to the generic Purchases module's native order/product/UOM/tax lifecycle or a final PDF/email dispatch workflow**. Before treating it as the company's official native purchase order, integrate the approved PO into the Purchases module and test its vendor, currency, item, tax, numbering, print, and approval requirements.

Role separation must be configured and tested so only authorized internal users can see the private BOQ and only designated reviewers/releasers can perform their respective actions. The current screen uses the resource's standard create/update permissions; approval and release do not yet have distinct named permission gates. Migrations, Filament actions, concurrency behavior, and runtime behavior have not been tested in a running application.
