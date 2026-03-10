<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\StoreAvisoRequest;
use App\Http\Requests\UpdateAvisoRequest;
use App\Models\Aviso;
use App\Models\AvisoLeitura;
use App\Services\AuditoriaEventoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvisoController extends Controller
{
    public function __construct(private readonly AuditoriaEventoService $auditoria)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $usuarioId = (int) auth()->id();

        $query = Aviso::query()
            ->with([
                'leituras' => fn ($q) => $q->where('usuario_id', $usuarioId),
            ]);

        $ativos = (int) $request->query('ativos', 1) === 1;
        if ($ativos) {
            $query->ativos();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $busca = '%' . trim((string) $request->string('search')) . '%';
            $query->where(function (Builder $q) use ($busca): void {
                $q->where('titulo', 'like', $busca)
                    ->orWhere('conteudo', 'like', $busca);
            });
        }

        $query
            ->orderByDesc('pinned')
            ->orderByRaw("CASE WHEN prioridade = 'importante' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(publicar_em, created_at) DESC');

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));

        $paginado = $query->paginate($perPage)->through(function (Aviso $aviso) {
            $arr = $aviso->toArray();
            $arr['lido'] = !empty($arr['leituras']);
            return $arr;
        });

        return response()->json($paginado);
    }

    public function store(StoreAvisoRequest $request): JsonResponse
    {
        if (!$this->podeGerenciar()) {
            return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
        }

        $payload = $request->validated();
        $payload['criado_por_usuario_id'] = auth()->id();
        $payload['atualizado_por_usuario_id'] = auth()->id();

        $aviso = Aviso::create($payload);

        $this->auditoria->registrar(
            module: 'avisos',
            action: 'create',
            label: 'Aviso criado',
            auditable: $aviso,
            metadata: [
                'pinned' => (bool) $aviso->pinned,
                'prioridade' => $aviso->prioridade,
                'status_novo' => $aviso->status,
            ]
        );

        return response()->json($aviso, 201);
    }

    public function show(Aviso $aviso): JsonResponse
    {
        $usuarioId = (int) auth()->id();
        $aviso->load([
            'leituras' => fn ($q) => $q->where('usuario_id', $usuarioId),
        ]);

        $arr = $aviso->toArray();
        $arr['lido'] = !empty($arr['leituras']);

        return response()->json($arr);
    }

    public function update(UpdateAvisoRequest $request, Aviso $aviso): JsonResponse
    {
        if (!$this->podeGerenciar()) {
            return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
        }

        $payload = $request->validated();
        $payload['atualizado_por_usuario_id'] = auth()->id();

        $before = $aviso->only(['titulo', 'conteudo', 'status', 'prioridade', 'pinned', 'publicar_em', 'expirar_em']);
        $aviso->fill($payload);

        $mudancas = [];
        foreach (array_keys($before) as $campo) {
            $old = $before[$campo] ?? null;
            $new = $aviso->{$campo};
            if ((string) $old === (string) $new) {
                continue;
            }

            $mudancas[] = [
                'campo' => $campo,
                'old' => $old,
                'new' => $new,
            ];
        }

        if ($aviso->isDirty()) {
            $statusAnterior = $before['status'] ?? null;
            $aviso->save();

            $this->auditoria->registrar(
                module: 'avisos',
                action: 'update',
                label: 'Aviso atualizado',
                auditable: $aviso,
                mudancas: $mudancas,
                metadata: [
                    'pinned' => (bool) $aviso->pinned,
                    'prioridade' => $aviso->prioridade,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $aviso->status,
                ]
            );
        }

        return response()->json($aviso->fresh());
    }

    public function destroy(Aviso $aviso): JsonResponse
    {
        if (!$this->podeGerenciar()) {
            return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
        }

        $statusAnterior = $aviso->status;

        if ($aviso->status !== 'arquivado') {
            $aviso->status = 'arquivado';
            $aviso->atualizado_por_usuario_id = auth()->id();
            $aviso->save();

            $this->auditoria->registrar(
                module: 'avisos',
                action: 'archive',
                label: 'Aviso arquivado',
                auditable: $aviso,
                mudancas: [[
                    'campo' => 'status',
                    'old' => $statusAnterior,
                    'new' => 'arquivado',
                ]],
                metadata: [
                    'status_anterior' => $statusAnterior,
                    'status_novo' => 'arquivado',
                ]
            );
        }

        return response()->json(['message' => 'Aviso arquivado com sucesso.']);
    }

    public function marcarComoLido(Aviso $aviso): JsonResponse
    {
        $usuarioId = (int) auth()->id();

        $leitura = AvisoLeitura::updateOrCreate(
            [
                'aviso_id' => $aviso->id,
                'usuario_id' => $usuarioId,
            ],
            [
                'lido_em' => now(),
            ]
        );

        return response()->json([
            'message' => 'Aviso marcado como lido.',
            'leitura' => $leitura,
        ]);
    }

    private function podeGerenciar(): bool
    {
        return AuthHelper::hasPermissao('avisos.manage');
    }
}
