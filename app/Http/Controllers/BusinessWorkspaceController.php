<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessRecordsRequest;
use App\Services\BusinessWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessWorkspaceController extends Controller
{
    public function __construct(private BusinessWorkspace $workspace) {}

    public function catalog(Request $request, string $module): JsonResponse
    {
        return response()->json($this->workspace->catalog($request->user(), $module))->header('Cache-Control', 'no-store');
    }

    public function records(BusinessRecordsRequest $request, string $module, string $section): JsonResponse
    {
        return response()->json($this->workspace->records($request->user(), $module, $section, $request->validated()))->header('Cache-Control', 'no-store');
    }
}
