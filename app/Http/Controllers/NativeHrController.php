<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveDecisionRequest;
use App\Http\Requests\NativeHrRequest;
use App\Services\NativeHr;
use App\Services\NativeLeave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NativeHrController extends Controller
{
    public function __construct(private NativeHr $hr) {}

    public function form(Request $request, string $kind): JsonResponse
    {
        return response()->json($this->hr->form($request->user(), $kind))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $kind, int $record): JsonResponse
    {
        return response()->json($this->hr->detail($request->user(), $kind, $this->hr->record($request->user(), $kind, $record)))->header('Cache-Control', 'no-store');
    }

    public function store(NativeHrRequest $request, string $kind): JsonResponse
    {
        $record = $this->persist($request, $kind);

        return response()->json($this->hr->detail($request->user(), $kind, $record), 201)->header('Cache-Control', 'no-store');
    }

    public function update(NativeHrRequest $request, string $kind, int $record): JsonResponse
    {
        $saved = $this->persist($request, $kind, $record);

        return response()->json($this->hr->detail($request->user(), $kind, $saved))->header('Cache-Control', 'no-store');
    }

    public function decide(LeaveDecisionRequest $request, string $kind, int $record): JsonResponse
    {
        try {
            $saved = app(NativeLeave::class)->decide($request->user(), $record, $request->validated('action'), $request->validated('revision'), $request->validated('reason'));
        } catch (QueryException $error) {
            Log::error('Native leave decision failed', ['record_id' => $record, 'sql_state' => $error->errorInfo[0] ?? $error->getCode()]);
            throw new HttpResponseException(response()->json(['message' => 'Unable to save this decision. Reopen the request before retrying.'], 500));
        }

        return response()->json($this->hr->detail($request->user(), 'leave', $saved))->header('Cache-Control', 'no-store');
    }

    private function persist(NativeHrRequest $request, string $kind, ?int $id = null): Model
    {
        try {
            return $this->hr->save($request->user(), $kind, [...$request->validated('values'), ...($id ? ['revision' => $request->validated('revision')] : ['request_id' => $request->validated('request_id')])], $id);
        } catch (QueryException $error) {
            Log::error('Native HR persistence failed', ['kind' => $kind, 'record_id' => $id, 'sql_state' => $error->errorInfo[0] ?? $error->getCode()]);
            throw new HttpResponseException(response()->json(['message' => 'Unable to save this profile. Your form is still open; retry or contact your ERP administrator.'], 500));
        }
    }
}
