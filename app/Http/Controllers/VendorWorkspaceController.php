<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorWorkspaceRequest;
use App\Services\NativeHr;
use App\Services\VendorRegistrationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Webkul\Purchase\Models\Partner;
use Webkul\Security\Models\Company;

class VendorWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(app(VendorRegistrationAccess::class)->view($request->user()), 403);
        $ids = app(NativeHr::class)->companyIds($request->user());

        return response()->json(['companies'=>Company::whereIn('id', $ids)->get(['id', 'name']), 'rows'=>Partner::where(fn ($q) =>$q->whereIn('company_id', $ids)->orWhereNull('company_id'))->where('supplier_rank', '>', 0)->orderBy('name')->limit(200)->get(), 'can_create'=>Gate::allows('create', Partner::class)]);
    }

    public function save(VendorWorkspaceRequest $request, ?int $record = null): JsonResponse
    {
        $data = $request->validated();
        $ids = app(NativeHr::class)->companyIds($request->user());
        abort_unless(in_array((int) $data['company_id'], $ids), 403);
        $vendor = $record ? Partner::whereIn('company_id', $ids)->findOrFail($record) : new Partner;
        Gate::authorize($record ? 'update' : 'create', $record ? $vendor : Partner::class);
        $vendor->fill($data);
        if (! $record) {
            $vendor->supplier_rank = 1;
            $vendor->creator_id = $request->user()->id;
        } $vendor->save();

        return response()->json(['id'=>$vendor->id]);
    }
}
