<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\AvisoIndexRequest;
use App\Http\Requests\AvisoStoreRequest;
use App\Http\Requests\AvisoUpdateRequest;
use App\Http\Resources\AvisoResource;
use App\Models\Aviso;
use App\Models\AvisoLeitura;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AvisoController extends Controller
{
    public function index(AvisoIndexRequest $request): JsonResponse
    {
        if (!AuthHelper::podeVisualizarAvisos()) {
            return response()->json(['message' => 'Sem permissão para visualizar avisos.'], 403);
        }

        $query = Aviso::query()->orderByDesc('created_at');

        if (
            AuthHelper::hasPermissao('avisos.view')
            && !AuthHelper::hasPermissao('avisos.visualizar')
            && $request->input('ativo') === null
            && $request->input('vigente') === null
            && trim((string) $request->input('q', '')) === ''
        ) {
            return response()->json([
                'data' => AvisoResource::collection($query->ativos()->get()),
            ]);
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('titulo', 'like', "%{$q}%")
                    ->orWhere('conteudo', 'like', "%{$q}%");
            });
        }

        if ($request->has('ativo')) {
            $query->where('ativo', $request->boolean('ativo'));
        }

        if ($request->has('vigente')) {
            $this->aplicarFiltroVigencia($query, $request->boolean('vigente'));
        }

        return response()->json([
            'data' => AvisoResource::collection($query->get()),
        ]);
    }

    public function ativos(AvisoIndexRequest $request): JsonResponse
    {
        if (!AuthHelper::podeVisualizarAvisos()) {
            return response()->json(['message' => 'Sem permissão para visualizar avisos.'], 403);
        }

        $limit = (int) ($request->validated()['limit'] ?? 10);

        $query = Aviso::query()
            ->where('ativo', true)
            ->orderByDesc('data_inicio')
            ->orderByDesc('created_at')
            ->limit($limit);

        $this->aplicarFiltroVigencia($query, true);

        return response()->json([
            'data' => AvisoResource::collection($query->get()),
        ]);
    }

    public function store(AvisoStoreRequest $request): JsonResponse
    {
        if (!AuthHelper::podeGerenciarAvisos()) {
            return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
        }

        $aviso = Aviso::create([
            ...$request->validated(),
            'criado_por' => auth()->id(),
        ]);

        return response()->json([
            'data' => new AvisoResource($aviso),
        ], 201);
    }

    public function show(Aviso $aviso): JsonResponse
    {
        if (!AuthHelper::podeVisualizarAvisos()) {
            return response()->json(['message' => 'Sem permissão para visualizar avisos.'], 403);
        }

        return response()->json([
            'data' => new AvisoResource($aviso),
        ]);
    }

    public function update(AvisoUpdateRequest $request, Aviso $aviso): JsonResponse
    {
        if (!AuthHelper::podeGerenciarAvisos()) {
            return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
        }

        $aviso->fill($request->validated());
        $aviso->save();

        return response()->json([
            'data' => new AvisoResource($aviso),
        ]);
    }

    public function destroy(Aviso $aviso): JsonResponse
    {
        if (!AuthHelper::podeGerenciarAvisos()) {
            return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
        }

        $aviso->forceFill(['ativo' => false])->save();

        return response()->json([
            'message' => 'Aviso inativado com sucesso.',
        ]);
    }

    public function marcarComoLido(Aviso $aviso): JsonResponse
    {
        $leitura = AvisoLeitura::updateOrCreate(
            [
                'aviso_id' => $aviso->id,
                'usuario_id' => (int) auth()->id(),
            ],
            ['lido_em' => now()]
        );

        return response()->json([
            'message' => 'Aviso marcado como lido.',
            'leitura' => $leitura,
        ]);
    }

    private function aplicarFiltroVigencia($query, bool $vigente): void
    {
        $agora = CarbonImmutable::now(config('app.timezone', 'America/Sao_Paulo'));

        if ($vigente) {
            $query
                ->where(function ($builder) use ($agora) {
                    $builder->whereNull('data_inicio')->orWhere('data_inicio', '<=', $agora);
                })
                ->where(function ($builder) use ($agora) {
                    $builder->whereNull('data_fim')->orWhere('data_fim', '>=', $agora);
                });

            return;
        }

        $query->where(function ($builder) use ($agora) {
            $builder
                ->where('ativo', false)
                ->orWhere('data_inicio', '>', $agora)
                ->orWhere('data_fim', '<', $agora);
        });
    }
}
