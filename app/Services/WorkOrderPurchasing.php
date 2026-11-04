<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\Product;
use Webkul\Project\Policies\ProgressAccess;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Security\Models\User;

class WorkOrderPurchasing
{
    private int $depth = 0;

    public function run(callable $callback): mixed
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    public function guardOrder(Order $order): void
    {
        if ($this->depth || ! ($order->work_order_id || $order->getRawOriginal('work_order_id'))) {
            return;
        }
        if (! $order->exists || $order->isDirty(['work_order_id', 'company_id', 'currency_id', 'partner_id', 'state'])) {
            throw ValidationException::withMessages(['work_order' => 'Manage Work Order-linked POs in V2 to preserve quantity allocations.']);
        }
    }

    public function guardLine(OrderLine $line, bool $deleting = false): void
    {
        if ($this->depth || ! ($line->order?->work_order_id || ($line->isDirty('order_id') && Order::find($line->getRawOriginal('order_id'))?->work_order_id))) {
            return;
        }
        if ($deleting || ! $line->exists || $line->isDirty(['order_id', 'product_id', 'uom_id', 'product_qty', 'product_uom_qty', 'price_unit', 'discount', 'name'])) {
            throw ValidationException::withMessages(['work_order' => 'Edit Work Order-linked PO items in V2 so awarded quantities are rechecked.']);
        }
    }

    public function options(User $user, int $company, ?int $exclude = null): array
    {
        $ids = app(ProgressAccess::class)->projects($user)->select('projects_projects.id');

        return WorkOrder::query()->where('company_id', $company)->whereIn('project_id', $ids)->where('state', 'active')->whereNotNull('current_version_id')->with(['project', 'currentVersion'])->get()->map(function ($wo) use ($exclude): array {
            $lines = collect($wo->currentVersion->data['lines'])->map(fn ($l) => ['key'=>$l['key'], 'item_id'=>$l['item_id'], 'description'=>$l['description'], 'route_name'=>collect($wo->currentVersion->data['routes'])->firstWhere('key', $l['route_key'])['name'], 'unit'=>$l['unit'], 'route_key'=>$l['route_key'], 'planned_subcon_rate'=>$l['subcon_rate'], 'available'=>(string) app(WorkOrders::class)->decimal($l['quantity'])->minus(app(WorkOrders::class)->allocated($wo, $l['key'], $exclude))])->all();

            return ['id'=>$wo->id, 'name'=>$wo->reference.' · '.$wo->project->name, 'company_id'=>$wo->company_id, 'currency_id'=>$wo->currency_id, 'version_id'=>$wo->current_version_id, 'lines'=>$lines];
        })->all();
    }

    public function prepare(User $user, array $data, ?Order $order = null): array
    {
        $id = (int) ($data['work_order_id'] ?? 0);
        if (! $id) {
            abort_if($order?->work_order_id, 422, 'A Work Order cannot be removed from its PO.');
            abort_if(collect($data['lines'])->contains(fn ($line) => filled($line['work_order_line_key'] ?? null)), 422, 'Select the source Work Order.');

            return [];
        }
        $wo = WorkOrder::query()->where('company_id', $data['company_id'])->whereIn('project_id', app(ProgressAccess::class)->projects($user)->select('projects_projects.id'))->lockForUpdate()->findOrFail($id);
        abort_unless($wo->state === 'active' && $wo->current_version_id && $wo->currentVersion->state === 'approved', 422, 'Select an active approved Work Order.');
        abort_unless((int) $data['currency_id'] === (int) $wo->currency_id, 422, 'PO currency must match its Work Order to preserve rate and margin comparisons.');
        abort_if($order?->work_order_id && (int) $order->work_order_id !== $wo->id, 422, 'Use a new PO to select a different Work Order.');
        abort_unless((int) ($data['work_order_version_id'] ?? 0) === (int) $wo->current_version_id, 409, 'The Work Order changed. Reopen this PO to review its latest version.');
        $current = collect($wo->currentVersion->data['lines'])->keyBy('key');
        $grouped = [];
        $prepared = [];
        foreach ($data['lines'] as $index => $line) {
            $source = $current->get($line['work_order_line_key'] ?? '');
            abort_unless($source && (int) $source['item_id'] === (int) $line['item_id'], 422, 'Each PO item must match a current Work Order item line.');
            abort_unless((int) Product::query()->findOrFail($source['item_id'])->uom_id === (int) $source['uom_id'], 422, 'This Item unit changed after the award. Review the Work Order item and unit before purchasing.');
            $quantity = app(WorkOrders::class)->decimal($line['quantity']);
            $key = $source['key'];
            $grouped[$key] = ($grouped[$key] ?? BigDecimal::zero())->plus($quantity);
            $used = app(WorkOrders::class)->allocated($wo, $key, $order?->id)->plus($grouped[$key]);
            abort_if($used->isGreaterThan($source['quantity']), 422, 'PO quantity exceeds the available Work Order balance for '.$source['description'].'.');
            $rate = app(WorkOrders::class)->decimal($line['unit_price'])->multipliedBy(BigDecimal::of(100)->minus($line['discount'] ?? 0))->dividedBy(100, 4, RoundingMode::HalfUp);
            $prepared[$index] = ['work_order_id'=>$wo->id, 'version_id'=>$wo->current_version_id, 'source_key'=>$key, 'quantity'=>(string) $quantity, 'maincon_rate'=>$source['maincon_rate'], 'subcon_rate'=>(string) $rate];
        }

        return $prepared;
    }

    public function sourceLabel(?WorkOrderAllocation $allocation): ?string
    {
        if (! $allocation) {
            return null;
        }
        $data = $allocation->version->data;
        $line = collect($data['lines'])->firstWhere('key', $allocation->source_key);
        $route = $line ? collect($data['routes'])->firstWhere('key', $line['route_key']) : null;

        return $route ? $route['name'].' · CH '.$route['chainage_from'].'–'.$route['chainage_to'].' · WO v'.$allocation->version->number : null;
    }

    public function assertExisting(User $user, Order $order): void
    {
        if (! $order->work_order_id) {
            return;
        }
        $allocations = WorkOrderAllocation::query()->where('order_id', $order->id)->get()->keyBy('order_line_id');
        $data = ['work_order_version_id' => $allocations->first()?->version_id, 'work_order_id'=>$order->work_order_id, 'company_id'=>$order->company_id, 'currency_id'=>$order->currency_id, 'lines'=>$order->lines->map(fn ($l) => ['work_order_line_key'=>$allocations->get($l->id)?->source_key, 'item_id'=>$l->product_id, 'quantity'=>$l->product_qty, 'unit_price'=>$l->price_unit, 'discount'=>$l->discount])->all()];
        $this->prepare($user, $data, $order);
        abort_unless($order->lines->count() === $allocations->count(), 409, 'Reopen this PO to restore its Work Order allocations.');
    }
}
