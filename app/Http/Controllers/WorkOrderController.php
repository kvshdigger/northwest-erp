<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderRequest;
use App\Services\WorkOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    public function __construct(private WorkOrders $orders) {}

    public function form(Request $request): JsonResponse
    {
        return response()->json($this->orders->form($request->user()))->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->orders->listing($request->user()))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $record): JsonResponse
    {
        return response()->json($this->orders->detail($request->user(), $this->orders->record($request->user(), $record)))->header('Cache-Control', 'no-store');
    }

    public function store(WorkOrderRequest $request, ?int $record = null): JsonResponse
    {
        $wo = $this->orders->save($request->user(), $request->validated(), $record);

        return response()->json($this->orders->detail($request->user(), $wo), 201)->header('Cache-Control', 'no-store');
    }

    public function update(WorkOrderRequest $request, int $record, int $version): JsonResponse
    {
        $wo = $this->orders->save($request->user(), $request->validated(), $record, $version);

        return response()->json($this->orders->detail($request->user(), $wo))->header('Cache-Control', 'no-store');
    }

    public function approve(WorkOrderRequest $request, int $record, int $version): JsonResponse
    {
        $wo = $this->orders->approve($request->user(), $record, $version, (int) $request->validated('revision'));

        return response()->json($this->orders->detail($request->user(), $wo))->header('Cache-Control', 'no-store');
    }
}
