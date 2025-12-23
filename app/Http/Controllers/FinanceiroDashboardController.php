<?php
namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\FinanceiroDashboardRequest;
use App\Services\FinanceiroDashboardService;
use Illuminate\Http\JsonResponse;

class FinanceiroDashboardController extends Controller
{
    public function __construct(private FinanceiroDashboardService $service) {}

    public function show(FinanceiroDashboardRequest $request): JsonResponse
    {
        $data = $this->service->resumo($request->validated());

        return response()->json([
            'data' => $data
        ]);
    }
}
