<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardHomePreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardHomePreferenceController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 20000;

    public function __construct(
        private readonly DashboardHomePreferenceService $service,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->service->get((int) auth()->id()));
    }

    public function update(Request $request): JsonResponse
    {
        $this->validatePayloadSize($request);

        $data = $request->validate([
            'version' => ['sometimes', 'integer', 'in:1'],
            'filters' => ['sometimes', 'array:period,inicio,fim,deposito_id,compare'],
            'filters.period' => ['nullable', 'in:today,7d,month,6m,custom'],
            'filters.inicio' => ['nullable', 'date_format:Y-m-d'],
            'filters.fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.inicio'],
            'filters.deposito_id' => ['nullable', 'integer', 'min:1'],
            'filters.compare' => ['nullable', 'boolean'],
            'layouts' => ['sometimes', 'array:lg,md,sm'],
            'layouts.lg' => ['sometimes', 'array', 'max:40'],
            'layouts.md' => ['sometimes', 'array', 'max:40'],
            'layouts.sm' => ['sometimes', 'array', 'max:40'],
            'layouts.*.*' => ['array:i,x,y,w,h'],
            'layouts.*.*.i' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9_.-]+\z/'],
            'layouts.*.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layouts.*.*.y' => ['required', 'integer', 'min:0', 'max:500'],
            'layouts.*.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layouts.*.*.h' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        if (($data['filters']['period'] ?? null) === 'custom'
            && (empty($data['filters']['inicio']) || empty($data['filters']['fim']))) {
            throw ValidationException::withMessages([
                'filters.inicio' => ['Informe o inicio e o fim para o periodo personalizado.'],
                'filters.fim' => ['Informe o inicio e o fim para o periodo personalizado.'],
            ]);
        }

        foreach (($data['layouts'] ?? []) as $breakpoint => $items) {
            $seen = [];
            foreach ($items as $index => $item) {
                $id = (string) $item['i'];
                if (isset($seen[$id])) {
                    throw ValidationException::withMessages([
                        "layouts.{$breakpoint}.{$index}.i" => ['Cada card pode aparecer apenas uma vez por breakpoint.'],
                    ]);
                }
                $seen[$id] = true;
            }
        }

        return response()->json($this->service->update((int) auth()->id(), $data));
    }

    public function destroy(): JsonResponse
    {
        return response()->json($this->service->delete((int) auth()->id()));
    }

    private function validatePayloadSize(Request $request): void
    {
        if (strlen((string) $request->getContent()) <= self::MAX_PAYLOAD_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'payload' => ['Preferencia muito grande para ser salva.'],
        ]);
    }
}
