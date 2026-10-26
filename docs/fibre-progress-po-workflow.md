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
- Each final PM/IM approval can be allocated to one non-rejected PO line at a time. A package-level transaction lock and active-PO lookup prevent normal duplicate generation; validate this behavior under concurrency before production. Rejected PO records retain their audit lines but do not block generating a replacement.
- Client BOQ rates are not copied into PO lines. The line amount uses a snapshot of the assigned subcontractor rate.
- Released PO records are immutable. Corrections or scope changes should be recorded as a separate variation/correction workflow rather than silently editing the released record.
- Returning a PO for correction marks it rejected and retains its line history. A replacement can be generated from the same approved progress once the underlying issue is resolved.
- This PO workflow does not change or merge the original Aureus repository.

## Implementation boundary

The current implementation adds a Northwest ERP fibre progress PO register and a review/release workflow in the Projects plugin. It is a controlled, auditable PO record, but it is **not yet connected to the generic Purchases module's native order/product/UOM/tax lifecycle or a final PDF/email dispatch workflow**. Before treating it as the company's official native purchase order, integrate the approved PO into the Purchases module and test its vendor, currency, item, tax, numbering, print, and approval requirements.

Separate PO review and release permissions are now declared in Shield configuration and checked by the PO actions (`review_fibre_progress_purchase_order` and `release_fibre_progress_purchase_order`). Assign these only to designated internal roles, then verify with test accounts that unauthorized users cannot invoke the actions. The Fibre BOQ resource contains internal client pricing; restrict its view/create/update/delete permissions to the internal project/management team and do not grant them to subcontractor-facing accounts. This repository change does not itself assign user roles. Native Purchases-module integration and final PDF/email dispatch remain outstanding. Migrations, Filament actions, concurrency behavior, and runtime behavior have not been tested in a running application.
