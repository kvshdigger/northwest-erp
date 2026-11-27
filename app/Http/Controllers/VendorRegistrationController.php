<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicVendorRegistrationRequest;
use App\Models\VendorRegistration;
use App\Services\NativeHr;
use App\Services\VendorRegistrationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Webkul\Purchase\Models\Partner;

class VendorRegistrationController extends Controller
{
    public function invite(Request $request): mixed
    {
        Gate::authorize('create', Partner::class);
        $id = $request->integer('company_id');
        abort_unless(in_array($id, app(NativeHr::class)->companyIds($request->user()), true), 403);
        $token = bin2hex(random_bytes(32));
        $r = VendorRegistration::create(['company_id'=>$id, 'token_hash'=>hash('sha256', $token), 'expires_at'=>now()->addHours(24), 'creator_id'=>$request->user()->id]);

        return response()->json(['url'=>url('/subcontractor-registration/'.$token), 'expires_at'=>$r->expires_at]);
    }

    public function requestLink(Request $request, string $token): mixed
    {
        (new VendorRegistration)->getConnection()->transaction(function () use ($token) {
            $r = VendorRegistration::where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_unless(in_array($r->state, ['invited', 'link_requested'], true) && $r->expires_at->isPast(), 410);
            $r->update(['state'=>'link_requested']);
        });

        return response()->view('vendors.registration', ['requested'=>true])->header('Cache-Control', 'no-store');
    }

    public function resend(Request $request, int $registration): mixed
    {
        Gate::authorize('create', Partner::class);

        return (new VendorRegistration)->getConnection()->transaction(function () use ($request, $registration) {
            $r = VendorRegistration::whereIn('company_id', app(NativeHr::class)->companyIds($request->user()))->lockForUpdate()->findOrFail($registration);
            abort_unless(in_array($r->state, ['invited', 'link_requested'], true), 409);
            $token = bin2hex(random_bytes(32));
            $r->update(['token_hash'=>hash('sha256', $token), 'state'=>'invited', 'expires_at'=>now()->addHours(24)]);

            return response()->json(['url'=>url('/subcontractor-registration/'.$token), 'expires_at'=>$r->expires_at]);
        });
    }

    public function pending(Request $request): mixed
    {
        abort_unless(app(VendorRegistrationAccess::class)->view($request->user()), 403);

        return response()->json(['can_review'=>app(VendorRegistrationAccess::class)->review($request->user()), 'rows'=>VendorRegistration::whereIn('company_id', app(NativeHr::class)->companyIds($request->user()))->whereIn('state', ['invited', 'link_requested', 'pending', 'approved', 'rejected'])->latest()->limit(100)->get()])->header('Cache-Control', 'private, no-store');
    }

    public function form(string $token): mixed
    {
        $invitation = VendorRegistration::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless(in_array($invitation->state, ['invited', 'link_requested'], true), 410);
        if ($invitation->expires_at->isPast()) {
            return response()->view('vendors.registration', ['token'=>$token, 'expired'=>true])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return response()->view('vendors.registration', ['token'=>$token])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function submit(PublicVendorRegistrationRequest $request, string $token): mixed
    {
        (new VendorRegistration)->getConnection()->transaction(function () use ($request, $token) {
            $r = VendorRegistration::where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_unless($r->state === 'invited' && $r->expires_at->isFuture(), 410);
            $r->update(['state'=>'pending', 'data'=>$request->validated()]);
        });

        return response()->view('vendors.registration', ['submitted'=>true])->header('Cache-Control', 'no-store');
    }

    public function review(Request $request, int $registration, string $decision): mixed
    {
        abort_unless(app(VendorRegistrationAccess::class)->review($request->user()), 403);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);

        return (new VendorRegistration)->getConnection()->transaction(function () use ($request, $registration, $decision) {
            $r = VendorRegistration::whereIn('company_id', app(NativeHr::class)->companyIds($request->user()))->lockForUpdate()->findOrFail($registration);
            abort_unless($r->state === 'pending', 409);
            $id = null;
            if ($decision === 'approve') {
                $data = $r->data;
                $contact = $data['contact_person'];
                unset($data['contact_person']);
                $v = Partner::create([...$data, 'company_id'=>$r->company_id, 'supplier_rank'=>1, 'creator_id'=>$request->user()->id, 'comment'=>'Registration contact: '.$contact]);
                $id = $v->id;
            }$r->update(['state'=>$decision === 'approve' ? 'approved' : 'rejected', 'reviewer_id'=>$request->user()->id, 'vendor_id'=>$id]);

            return response()->json(['state'=>$r->state, 'vendor_id'=>$id]);
        });
    }
}
