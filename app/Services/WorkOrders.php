<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Models\DailyProgressEntry;
use App\Models\WorkOrder;
use App\Models\WorkOrderRoute;
use App\Models\WorkOrderVersion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Project\Policies\ProgressAccess;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Currency;

class WorkOrders
{
    public function financialAccess(User $user): bool
    {
        return app(NativeFinance::class)->available($user, 'purchase-orders') && $user->can('view_purchase_purchase::order') && $user->can('view_accounting_invoice');
    }

    public function manage(User $user, Project $project): bool
    {
        return $this->financialAccess($user) && app(ProgressAccess::class)->configure($user, $project) && $user->can('create_purchase_purchase::order') && $user->can('update_purchase_purchase::order');
    }

    public function query(User $user): Builder
    {
        abort_unless($this->financialAccess($user), 403);

        return WorkOrder::query()->whereIn('company_id', app(NativeHr::class)->companyIds($user))->whereIn('project_id', app(ProgressAccess::class)->projects($user)->select('projects_projects.id'));
    }

    public function record(User $user, int $id, bool $lock = false): WorkOrder
    {
        return $this->query($user)->when($lock, fn ($q) => $q->lockForUpdate())->with('project')->findOrFail($id);
    }

    public function form(User $user): array
    {
        abort_unless($this->financialAccess($user), 403);
        $ids = app(NativeHr::class)->companyIds($user);
        $projects = app(ProgressAccess::class)->projects($user)->whereIn('company_id', $ids)->get(['id', 'name', 'company_id', 'user_id']);

        return ['projects' => $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'company_id' => $p->company_id, 'can_manage' => $this->manage($user, $p)])->all(),
            'partners'     => Partner::query()->where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'))->get(['id', 'name', 'company_id'])->toArray(),
            'items'        => Product::query()->with('uom')->where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'))->where('enable_purchase', true)->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'company_id' => $p->company_id, 'unit' => $p->uom?->name, 'price' => $p->getRawOriginal('price'), 'cost' => $p->getRawOriginal('cost')])->all(),
            'currencies'   => Currency::query()->get(['id', 'name'])->toArray()];
    }

    public function listing(User $user): array
    {
        $page = $this->query($user)->with(['project', 'currentVersion'])->orderByDesc('id')->simplePaginate(20);

        return ['rows' => $page->getCollection()->map(fn ($wo) => ['id' => $wo->id, 'reference' => $wo->reference, 'project' => $wo->project->name, 'version' => $wo->currentVersion?->number, 'state' => $wo->currentVersion ? 'approved' : 'draft', 'can_manage' => $this->manage($user, $wo->project)])->values()->all(), 'page' => $page->currentPage(), 'has_next' => $page->hasMorePages()];
    }

    public function detail(User $user, WorkOrder $wo): array
    {
        abort_unless($this->financialAccess($user), 403);
        $wo->load(['versions.routes.route', 'currentVersion', 'project']);

        return ['id'   => $wo->id, 'reference' => $wo->reference, 'project_id' => $wo->project_id, 'company_id' => $wo->company_id, 'partner_id' => $wo->partner_id, 'currency_id' => $wo->currency_id, 'current_version_id' => $wo->current_version_id, 'can_manage' => $this->manage($user, $wo->project),
            'versions' => $wo->versions->sortByDesc('number')->map(fn ($v) => ['id' => $v->id, 'number' => $v->number, 'revision' => $v->revision, 'state' => $v->state, 'data' => $v->data])->values()->all(),
            'summary'  => $wo->currentVersion ? $this->summary($wo) : null];
    }

    public function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }

    public function chainage(string $value): string
    {
        try {
            $value = trim($value);
            if (! preg_match('/^\d{1,8}(?:\s*\+\s*\d{1,3})?(?:\.\d{1,3})?$/', $value)) {
                throw new \InvalidArgumentException;
            }

            return number_format(DailyProgressEntry::parseChainage($value), 3, '.', '');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['chainage' => 'Use chainage such as 0+000 or 1+250.500, with up to three decimal places.']);
        }
    }

    public function save(User $user, array $input, ?int $id = null, ?int $versionId = null): WorkOrder
    {
        return (new WorkOrder)->getConnection()->transaction(function () use ($user, $input, $id, $versionId): WorkOrder {
            $data = $input['values'];
            $visibleProject = app(ProgressAccess::class)->projects($user)->whereKey($data['project_id'])->firstOrFail();
            $company = app(FinanceDocuments::class)->company($user, (int) $visibleProject->company_id, true);
            $project = app(ProgressAccess::class)->projects($user)->whereKey($data['project_id'])->lockForUpdate()->firstOrFail();
            abort_unless($this->manage($user, $project), 403);
            $wo = $id ? $this->record($user, $id, true) : new WorkOrder;
            $hash = hash('sha256', json_encode($data));
            if (! $versionId && $event = BusinessEvent::query()->where('actor_id', $user->id)->where('request_id', $input['request_id'])->first()) {
                abort_unless($event->kind === 'work-order' && $event->payload_hash === $hash && (! $id || (int) $event->record_id === $id), 409, 'Submission key already used.');

                return $this->record($user, $event->record_id);
            }
            $partner = Partner::query()->where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))->findOrFail($data['partner_id']);
            Currency::query()->findOrFail($data['currency_id']);
            $header = ['company_id' => $company->id, 'project_id' => $project->id, 'partner_id' => $partner->id, 'currency_id' => (int) $data['currency_id'], 'reference' => trim($data['reference'])];
            if ($id) {
                foreach ($header as $key => $value) {
                    abort_unless((string) $wo->{$key} === (string) $value, 422, 'Project, company, main contractor, currency and reference cannot change on an existing Work Order.');
                }
            } else {
                abort_if(WorkOrder::query()->where('company_id', $company->id)->where('reference', $header['reference'])->exists(), 422, 'Work Order reference already exists in this company.');
                $wo->fill($header)->save();
            }
            $version = $versionId ? $wo->versions()->whereKey($versionId)->lockForUpdate()->firstOrFail() : new WorkOrderVersion(['work_order_id' => $wo->id, 'number' => ((int) $wo->versions()->max('number')) + 1, 'creator_id' => $user->id]);
            if ($versionId) {
                abort_unless($version->state === 'draft', 409, 'Approved versions cannot be changed. Create a variation.');
                abort_unless($version->revision === (int) $input['revision'], 409, 'This draft changed. Reopen it.');
                $version->revision++;
            } else {
                abort_if($wo->versions()->where('state', 'draft')->exists(), 409, 'Finish the existing draft before creating another variation.');
            }
            $routes = [];
            foreach ($data['routes'] as $route) {
                $route['name'] = trim($route['name']);
                $route['chainage_from'] = $this->chainage($route['chainage_from']);
                $route['chainage_to'] = $this->chainage($route['chainage_to']);
                abort_unless($this->decimal($route['chainage_to'])->isGreaterThan($route['chainage_from']), 422, 'Route end chainage must exceed its start.');
                $routes[$route['key']] = $route;
            }
            $lines = [];
            foreach ($data['lines'] as $line) {
                abort_unless(isset($routes[$line['route_key']]), 422, 'Each item must belong to a route in this Work Order.');
                $item = Product::query()->with('uom')->where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))->where('enable_purchase', true)->findOrFail($line['item_id']);
                $lines[] = [...$line, 'item_name' => $item->name, 'uom_id' => $item->uom_id, 'unit' => $item->uom?->name, 'quantity' => (string) $this->decimal($line['quantity'])->toScale(4), 'maincon_rate' => (string) $this->decimal($line['maincon_rate'])->toScale(4), 'subcon_rate' => (string) $this->decimal($line['subcon_rate'])->toScale(4)];
            }
            $version->data = ['title' => $data['title'], 'reason' => $data['reason'], 'routes' => array_values($routes), 'lines' => $lines];
            $version->save();
            $this->audit($user, $wo, 'draft_saved', ['version_id' => $version->id, 'data' => $version->data], $versionId ? null : $input['request_id'], $versionId ? null : $hash);

            return $wo->fresh();
        });
    }

    public function approve(User $user, int $id, int $versionId, int $revision): WorkOrder
    {
        return (new WorkOrder)->getConnection()->transaction(function () use ($user, $id, $versionId, $revision): WorkOrder {
            $wo = $this->record($user, $id);
            app(FinanceDocuments::class)->company($user, $wo->company_id, true);
            $project = $wo->project()->lockForUpdate()->firstOrFail();
            $wo = $this->record($user, $id, true);
            abort_unless($this->manage($user, $project), 403);
            $version = $wo->versions()->whereKey($versionId)->lockForUpdate()->firstOrFail();
            abort_unless($version->state === 'draft' && $version->revision === $revision, 409, 'Reopen the current draft before approving.');
            $data = $version->data;
            $new = collect($data['lines'])->keyBy('key');
            foreach ($wo->versions()->where('state', 'approved')->get()->flatMap(fn ($v) => $v->data['lines'])->unique('key')->all() as $previous) {
                $allocated = $this->allocated($wo, $previous['key']);
                $line = $new->get($previous['key']);
                abort_if(! $line && $allocated->isGreaterThan(0), 422, 'An allocated item cannot be removed. Cancel or reduce its POs first.');
                if ($line) {
                    foreach (['item_id', 'uom_id', 'route_key'] as $field) {
                        abort_unless((string) $line[$field] === (string) $previous[$field], 422, 'Use a new item line when changing its item, unit or route.');
                    }
                    abort_if($this->decimal($line['quantity'])->isLessThan($allocated), 422, 'Awarded quantity cannot be reduced below reserved PO quantities.');
                }
            }
            $oldRoutes = $wo->versions()->where('state', 'approved')->get()->flatMap(fn ($v) => $v->data['routes'])->unique('key')->keyBy('key');
            foreach ($data['routes'] as $segment) {
                $oldRoute = $oldRoutes->get($segment['key']);
                abort_if($oldRoute && mb_strtolower($oldRoute['name']) !== mb_strtolower($segment['name']), 422, 'Use a new route row when changing the route name.');
                $route = ProgressRoute::query()->firstOrCreate(['project_id' => $project->id, 'name_key' => mb_strtolower($segment['name'])], ['name' => $segment['name'], 'is_active' => true]);
                if (! $route->is_active) {
                    $route->update(['is_active' => true]);
                }
                WorkOrderRoute::create(['version_id' => $version->id, 'route_id' => $route->id, 'source_key' => $segment['key'], 'chainage_from' => $segment['chainage_from'], 'chainage_to' => $segment['chainage_to']]);
            }
            $version->forceFill(['state' => 'approved', 'revision' => $version->revision + 1, 'approver_id' => $user->id])->save();
            $wo->current_version_id = $version->id;
            $wo->save();
            $this->audit($user, $wo, 'approved', ['version_id' => $version->id, 'number' => $version->number]);

            return $wo->fresh();
        });
    }

    public function routeQuery(Project $project): Builder
    {
        return WorkOrderRoute::query()->whereHas('version', fn ($q) => $q->where('state', 'approved'))->whereHas('version.workOrder', fn ($q) => $q->where('project_id', $project->id)->where('state', 'active')->whereColumn('current_version_id', 'v2_work_order_routes.version_id'))->whereHas('route', fn ($q) => $q->where('is_active', true))->with(['route', 'version.workOrder']);
    }

    public function routes(Project $project): array
    {
        return $this->routeQuery($project)->get()->map(fn ($r) => ['id' => $r->route_id, 'name' => $r->route->name, 'work_order_route_id' => $r->id, 'work_order_reference' => $r->version->workOrder->reference, 'version' => $r->version->number, 'chainage_from' => $r->chainage_from, 'chainage_to' => $r->chainage_to])->all();
    }

    public function progress(Project $project, array $data): array
    {
        $route = $this->routeQuery($project)->whereKey($data['work_order_route_id'] ?? 0)->first();
        abort_unless($route && (int) $route->route_id === (int) $data['route_id'], 422, 'Select an authorised Work Order route for this project.');
        $from = $this->chainage($data['chainage_from'] ?? '');
        $to = $this->chainage($data['chainage_to'] ?? '');
        abort_unless($this->decimal($from)->isGreaterThanOrEqualTo($route->chainage_from) && $this->decimal($to)->isLessThanOrEqualTo($route->chainage_to) && $this->decimal($to)->isGreaterThanOrEqualTo($from), 422, 'Progress chainage must fall within the selected Work Order range.');

        return ['work_order_route_id' => $route->id, 'chainage_from' => $from, 'chainage_to' => $to];
    }

    public function allocations(WorkOrder $wo, ?int $exclude = null): Collection
    {
        return $wo->allocations()->whereHas('order', fn ($q) => $q->whereNotIn('state', ['cancel', 'canceled'])->when($exclude, fn ($q) => $q->whereKeyNot($exclude)))->get();
    }

    public function allocated(WorkOrder $wo, string $key, ?int $exclude = null): BigDecimal
    {
        return $this->allocations($wo, $exclude)->where('source_key', $key)->reduce(fn ($sum, $a) => $sum->plus($a->quantity), BigDecimal::zero());
    }

    public function summary(WorkOrder $wo): array
    {
        $rows = [];
        $revenue = $cost = $forecast = BigDecimal::zero();
        $allocations = $this->allocations($wo);
        foreach ($wo->currentVersion->data['lines'] as $line) {
            $selected = $allocations->where('source_key', $line['key']);
            $used = $selected->reduce(fn ($sum, $a) => $sum->plus($a->quantity), BigDecimal::zero());
            $committed = $selected->reduce(fn ($sum, $a) => $sum->plus($this->decimal($a->quantity)->multipliedBy($a->subcon_rate)), BigDecimal::zero());
            $awarded = $this->decimal($line['quantity'])->multipliedBy($line['maincon_rate']);
            $remaining = $this->decimal($line['quantity'])->minus($used);
            $forecastCost = $committed->plus($remaining->multipliedBy($line['subcon_rate']));
            $segment = collect($wo->currentVersion->data['routes'])->firstWhere('key', $line['route_key']);
            $rows[] = [...$line, 'route_name' => $segment['name'], 'chainage_from' => $segment['chainage_from'], 'chainage_to' => $segment['chainage_to'], 'allocated' => (string) $used, 'available' => (string) $remaining, 'revenue' => (string) $awarded->toScale(2, RoundingMode::HalfUp), 'committed_cost' => (string) $committed->toScale(2, RoundingMode::HalfUp), 'forecast_margin' => (string) $awarded->minus($forecastCost)->toScale(2, RoundingMode::HalfUp)];
            $revenue = $revenue->plus($awarded);
            $cost = $cost->plus($committed);
            $forecast = $forecast->plus($forecastCost);
        }
        $margin = $revenue->minus($forecast);

        return ['currency' => Currency::find($wo->currency_id)?->name, 'lines' => $rows, 'revenue' => (string) $revenue->toScale(2, RoundingMode::HalfUp), 'reserved_subcon_cost' => (string) $cost->toScale(2, RoundingMode::HalfUp), 'forecast_gross_margin' => (string) $margin->toScale(2, RoundingMode::HalfUp), 'forecast_margin_percent' => $revenue->isZero() ? null : (string) $margin->multipliedBy(100)->dividedBy($revenue, 2, RoundingMode::HalfUp)];
    }

    private function audit(User $user, WorkOrder $wo, string $action, array $changes, ?string $key = null, ?string $hash = null): void
    {
        BusinessEvent::create(['company_id' => $wo->company_id, 'actor_id' => $user->id, 'kind' => 'work-order', 'record_id' => $wo->id, 'action' => $action, 'request_id' => $key, 'payload_hash' => $hash, 'changes' => $changes]);
    }
}
