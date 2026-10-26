<?php

namespace Webkul\Project\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Project\Models\FibreClaim;
use Webkul\Project\Models\FibreClaimItem;
use Webkul\Project\Models\FibreProgressApproval;
use Webkul\Project\Models\FibreWorkPackageItem;

class FibreClaimBalance
{
    public static function approvedQuantity(FibreWorkPackageItem $item): float
    {
        return (float) FibreProgressApproval::query()
            ->join('projects_fibre_daily_progress as progress', 'progress.id', '=', 'projects_fibre_progress_approvals.progress_id')
            ->where('progress.work_package_item_id', $item->id)
            ->where('progress.status', 'submitted')
            ->where('projects_fibre_progress_approvals.event_type', FibreProgressApproval::PM_APPROVED)
            ->sum('projects_fibre_progress_approvals.quantity');
    }

    public static function reservedQuantity(FibreWorkPackageItem $item): float
    {
        return (float) FibreClaimItem::query()
            ->join('projects_fibre_claims as claims', 'claims.id', '=', 'projects_fibre_claim_items.claim_id')
            ->where('projects_fibre_claim_items.work_package_item_id', $item->id)
            ->whereIn('claims.status', [
                FibreClaim::SUBMITTED, FibreClaim::REVIEWED, FibreClaim::APPROVED,
                FibreClaim::PARTIALLY_PAID, FibreClaim::PAID,
            ])
            ->sum('projects_fibre_claim_items.quantity_claimed');
    }

    public static function paidQuantity(FibreWorkPackageItem $item): float
    {
        return (float) DB::table('projects_fibre_claim_payments')
            ->join('projects_fibre_claim_items', 'projects_fibre_claim_items.id', '=', 'projects_fibre_claim_payments.claim_item_id')
            ->where('projects_fibre_claim_items.work_package_item_id', $item->id)
            ->sum('projects_fibre_claim_payments.quantity_paid');
    }
}