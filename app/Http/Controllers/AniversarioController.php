<?php

namespace App\Http\Controllers;

use App\Http\Requests\AniversarioIndexRequest;
use App\Services\AniversarioService;
use Illuminate\Http\JsonResponse;

class AniversarioController extends Controller
{
    public function __construct(private readonly AniversarioService $service)
    {
    }

    public function index(AniversarioIndexRequest $request): JsonResponse
    {
        return response()->json($this->service->listar($request->validated()));
    }
}
