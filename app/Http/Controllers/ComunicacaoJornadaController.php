<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\ComunicacaoJornada;
use App\Services\Comunicacao\ComunicacaoJornadaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComunicacaoJornadaController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(ComunicacaoJornada::query()->with(['eventos', 'canais'])->orderBy('nome')->get());
    }

    public function show(ComunicacaoJornada $jornada): JsonResponse
    {
        $this->authorizeView();

        return response()->json($jornada->load(['eventos', 'canais']));
    }

    public function store(Request $request, ComunicacaoJornadaService $service): JsonResponse
    {
        $this->authorizeManage();
        $jornada = $service->salvar(null, $this->validatePayload($request));

        return response()->json($jornada, 201);
    }

    public function update(Request $request, ComunicacaoJornada $jornada, ComunicacaoJornadaService $service): JsonResponse
    {
        $this->authorizeManage();

        return response()->json($service->salvar($jornada, $this->validatePayload($request, $jornada)));
    }

    public function activation(Request $request, ComunicacaoJornada $jornada, ComunicacaoJornadaService $service): JsonResponse
    {
        $this->authorizeManage();
        $data = $request->validate(['ativo' => ['required', 'boolean']]);

        return response()->json($service->ativar($jornada, (bool) $data['ativo']));
    }

    private function validatePayload(Request $request, ?ComunicacaoJornada $jornada = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:100', Rule::unique('comunicacao_jornadas', 'codigo')->ignore($jornada?->id)],
            'nome' => ['required', 'string', 'max:150'],
            'tipo' => ['required', Rule::in(['pedido', 'cobranca'])],
            'timezone' => ['sometimes', 'string', Rule::in(['America/Belem'])],
            'agenda' => ['nullable', 'array'],
            'agenda.marcos' => ['nullable', 'array'],
            'agenda.marcos.*' => ['integer', Rule::in([-3, 0, 3, 7])],
            'agenda.hora' => ['nullable', 'date_format:H:i'],
            'eventos' => ['present', 'array'],
            'eventos.*' => ['string', 'max:100'],
            'canais' => ['present', 'array', 'max:3'],
            'canais.*.canal' => ['required', Rule::in(['email', 'sms', 'whatsapp']), 'distinct'],
            'canais.*.template_codigo' => ['required', 'string', 'max:120'],
            'canais.*.ativo' => ['required', 'boolean'],
        ]);
    }

    private function authorizeView(): void
    {
        abort_unless(
            AuthHelper::hasPermissao('comunicacao.visualizar') || AuthHelper::hasPermissao('comunicacao.templates'),
            403,
            'Sem permissão para visualizar comunicação.'
        );
    }

    private function authorizeManage(): void
    {
        abort_unless(AuthHelper::hasPermissao('comunicacao.templates'), 403, 'Sem permissão para configurar comunicação.');
    }
}
