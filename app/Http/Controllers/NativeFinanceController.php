<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceConfigurationRequest;
use App\Http\Requests\NativeFinanceRequest;
use App\Models\BusinessEvent;
use App\Services\FinanceDocuments;
use App\Services\NativeFinance;
use App\Services\NativeHr;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NativeFinanceController extends Controller
{
    public function __construct(private NativeFinance $finance, private FinanceDocuments $documents) {}

    public function form(Request $request, string $kind): JsonResponse
    {
        $company = $request->integer('company_id') ?: (app(NativeHr::class)->companyIds($request->user())[0] ?? 0);

        if ($request->integer('order_id')) {
            abort_unless($kind === 'purchase-orders', 422);
            $this->finance->record($request->user(), $kind, $request->integer('order_id'));
        }

        return response()->json($this->finance->form($request->user(), $kind, $company, $request->integer('order_id') ?: null))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $kind, int $record): JsonResponse
    {
        return response()->json($this->finance->detail($request->user(), $kind, $this->finance->record($request->user(), $kind, $record)))->header('Cache-Control', 'no-store');
    }

    public function store(NativeFinanceRequest $request, string $kind): JsonResponse
    {
        return $this->persist($request, $kind);
    }

    public function update(NativeFinanceRequest $request, string $kind, int $record): JsonResponse
    {
        return $this->persist($request, $kind, $record);
    }

    private function persist(NativeFinanceRequest $request, string $kind, ?int $id = null): JsonResponse
    {
        try {
            $row = $this->finance->save($request->user(), $kind, $request->validated(), $id);
        } catch (QueryException $error) {
            $this->databaseError($error);
        }

        return response()->json($this->finance->detail($request->user(), $kind, $row), $id ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function confirm(NativeFinanceRequest $request, string $kind, int $record): JsonResponse
    {
        try {
            $row = $this->finance->confirm($request->user(), $kind, $record, $request->validated('revision'));
        } catch (QueryException $error) {
            $this->databaseError($error);
        } catch (HttpException|ValidationException $error) {
            throw $error;
        } catch (\Exception $error) {
            throw ValidationException::withMessages(['setup' => 'Confirmation could not complete. Check the company accounting, tax, stock and journal setup before retrying.']);
        }

        return response()->json($this->finance->detail($request->user(), $kind, $row))->header('Cache-Control', 'no-store');
    }

    public function cancel(NativeFinanceRequest $request, string $kind, int $record): JsonResponse
    {
        abort_unless($kind === 'purchase-orders', 404);
        $row = $this->finance->cancel($request->user(), $record, $request->validated('revision'));

        return response()->json($this->finance->detail($request->user(), $kind, $row))->header('Cache-Control', 'no-store');
    }

    public function configuration(Request $request): JsonResponse
    {
        $company = $request->integer('company_id') ?: (app(NativeHr::class)->companyIds($request->user())[0] ?? 0);

        return response()->json($this->documents->configuration($request->user(), $company))->header('Cache-Control', 'no-store');
    }

    public function saveConfiguration(FinanceConfigurationRequest $request): JsonResponse
    {
        return response()->json($this->documents->save($request->user(), $request->validated(), $request->file('logo')))->header('Cache-Control', 'no-store');
    }

    public function print(Request $request, string $kind, int $record): mixed
    {
        abort_unless(in_array($kind, ['purchase-orders', 'invoices', 'bills'], true), 404);
        $row = $this->finance->record($request->user(), $kind, $record);
        $document = $this->finance->detail($request->user(), $kind, $row);
        $event = BusinessEvent::query()->where('kind', 'finance-'.$kind)->where('record_id', $record)->where('company_id', $row->company_id)->whereIn('action', ['created', 'updated', 'confirmed'])->latest('id')->first();
        $branding = $event?->changes['document'] ?? $this->documents->snapshot($this->documents->company($request->user(), $row->company_id));

        return response()->view('finance.document', ['document' => $document, 'branding' => $branding, 'partner' => $row->partner, 'row' => $row])->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'none'")->header('X-Content-Type-Options', 'nosniff');
    }

    private function databaseError(QueryException $error): never
    {
        Log::error('V2 finance persistence failed', ['sql_state' => $error->errorInfo[0] ?? $error->getCode()]);
        throw new HttpResponseException(response()->json(['message' => 'Unable to save. Check company accounting setup and reopen the record before retrying.'], 422));
    }
}
