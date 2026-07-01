<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UsuarioPreferenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UsuarioPreferenciaController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 20000;

    public function __construct(
        private readonly UsuarioPreferenciaService $service,
    ) {}

    public function show(string $screenKey): JsonResponse
    {
        $this->validarScreenKey($screenKey);

        return response()->json(
            $this->service->obterTela((int) auth()->id(), $screenKey)
        );
    }

    public function update(Request $request, string $screenKey): JsonResponse
    {
        $this->validarScreenKey($screenKey);
        $this->validarTamanhoPayload($request);

        $data = $request->validate([
            'version' => ['sometimes', 'integer', 'in:1'],
            'filters' => ['sometimes', 'array'],
            'tables' => ['sometimes', 'array', 'max:40'],
            'tables.*' => ['array'],
            'tables.*.hidden_columns' => ['sometimes', 'array', 'max:120'],
            'tables.*.hidden_columns.*' => ['string', 'max:120', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'tables.*.first' => ['nullable', 'integer', 'min:0'],
            'tables.*.rows' => ['nullable', 'integer', 'min:1', 'max:500'],
            'tables.*.sort' => ['nullable', 'array'],
            'tables.*.sort.field' => ['nullable', 'string', 'max:120', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'tables.*.sort.order' => ['nullable'],
        ]);

        return response()->json(
            $this->service->atualizarTela((int) auth()->id(), $screenKey, $data)
        );
    }

    public function destroy(string $screenKey): JsonResponse
    {
        $this->validarScreenKey($screenKey);

        $this->service->removerTela((int) auth()->id(), $screenKey);

        return response()->json(
            $this->service->preferenciaPadrao()
        );
    }

    private function validarScreenKey(string $screenKey): void
    {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $screenKey)) {
            return;
        }

        throw ValidationException::withMessages([
            'screenKey' => ['Identificador de tela invalido.'],
        ]);
    }

    private function validarTamanhoPayload(Request $request): void
    {
        if (strlen((string) $request->getContent()) <= self::MAX_PAYLOAD_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'payload' => ['Preferencia muito grande para ser salva.'],
        ]);
    }
}
