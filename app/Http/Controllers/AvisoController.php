<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\AvisoStoreRequest;
use App\Http\Requests\AvisoUpdateRequest;
use App\Models\Aviso;
use App\Models\AvisoLeitura;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvisoController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->autorizarVisualizacao()) {
            return $forbidden;
        }

        $filtros = $request->validate([
            'status' => ['nullable', 'in:rascunho,publicado,arquivado'],
            'ativos' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $usuarioId = AuthHelper::getUsuarioId();
        $query = Aviso::query();

        $somenteAtivos = !array_key_exists('ativos', $filtros)
            ? true
            : filter_var($filtros['ativos'], FILTER_VALIDATE_BOOL);

        if (!empty($filtros['status'])) {
            $query->where('status', $filtros['status']);
        } elseif ($somenteAtivos) {
            $query->ativos();
        }

        if (!empty($filtros['search'])) {
            $search = trim((string) $filtros['search']);
            $query->where(function ($sub) use ($search) {
                $sub->where('titulo', 'like', "%{$search}%")
                    ->orWhere('conteudo', 'like', "%{$search}%");
            });
        }

        if ($usuarioId) {
            $query->withExists([
                'leituras as lido' => fn ($q) => $q->where('usuario_id', $usuarioId),
            ]);
        }

        $query
            ->orderByDesc('pinned')
            ->orderByRaw("CASE prioridade WHEN 'importante' THEN 0 ELSE 1 END")
            ->orderByDesc(DB::raw('COALESCE(publicar_em, created_at)'))
            ->orderByDesc('created_at');

        $perPage = (int) ($filtros['per_page'] ?? 20);
        $paginado = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginado->items())->map(fn (Aviso $aviso) => $this->mapAviso($aviso)),
            'meta' => [
                'current_page' => $paginado->currentPage(),
                'per_page' => $paginado->perPage(),
                'total' => $paginado->total(),
                'last_page' => $paginado->lastPage(),
            ],
        ]);
    }

    public function store(AvisoStoreRequest $request): JsonResponse
    {
        if ($forbidden = $this->autorizarGerenciamento()) {
            return $forbidden;
        }

        $usuarioId = AuthHelper::getUsuarioId();
        $dados = $request->validated();
        $dados['criado_por_usuario_id'] = $usuarioId;
        $dados['atualizado_por_usuario_id'] = $usuarioId;

        $aviso = DB::transaction(function () use ($dados) {
            $aviso = Aviso::create($dados);

            $this->auditLogger->logCreate($aviso, 'avisos', 'Aviso criado', [
                'pinned' => $aviso->pinned,
                'prioridade' => $aviso->prioridade,
                'status_novo' => $aviso->status,
            ]);

            return $aviso;
        });

        return response()->json(['data' => $this->mapAviso($aviso)], 201);
    }

    public function show(int $id): JsonResponse
    {
        if ($forbidden = $this->autorizarVisualizacao()) {
            return $forbidden;
        }

        $usuarioId = AuthHelper::getUsuarioId();
        $query = Aviso::query();

        if ($usuarioId) {
            $query->withExists([
                'leituras as lido' => fn ($q) => $q->where('usuario_id', $usuarioId),
            ]);
        }

        $aviso = $query->findOrFail($id);
        return response()->json(['data' => $this->mapAviso($aviso)]);
    }

    public function update(AvisoUpdateRequest $request, int $id): JsonResponse
    {
        if ($forbidden = $this->autorizarGerenciamento()) {
            return $forbidden;
        }

        $aviso = Aviso::query()->findOrFail($id);
        $dados = $request->validated();
        $dados['atualizado_por_usuario_id'] = AuthHelper::getUsuarioId();

        $antes = $aviso->getAttributes();
        $aviso->fill($dados);
        $dirty = $aviso->getDirty();

        if (!empty($dirty)) {
            $aviso->save();

            $this->auditLogger->logUpdate($aviso, 'avisos', 'Aviso atualizado', [
                '__before' => $antes,
                '__dirty' => $dirty,
                'pinned' => $aviso->pinned,
                'prioridade' => $aviso->prioridade,
                'status_anterior' => $antes['status'] ?? null,
                'status_novo' => $aviso->status,
            ]);
        }

        return response()->json(['data' => $this->mapAviso($aviso->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($forbidden = $this->autorizarGerenciamento()) {
            return $forbidden;
        }

        $aviso = Aviso::query()->findOrFail($id);
        $antes = $aviso->getAttributes();

        if ($aviso->status !== 'arquivado') {
            $aviso->status = 'arquivado';
            $aviso->atualizado_por_usuario_id = AuthHelper::getUsuarioId();
            $aviso->save();

            $this->auditLogger->logUpdate($aviso, 'avisos', 'Aviso arquivado', [
                '__before' => $antes,
                '__dirty' => ['status' => 'arquivado'],
                'status_anterior' => $antes['status'] ?? null,
                'status_novo' => 'arquivado',
            ]);
        }

        return response()->json(null, 204);
    }

    public function ler(int $id): JsonResponse
    {
        if ($forbidden = $this->autorizarVisualizacao()) {
            return $forbidden;
        }

        $usuarioId = AuthHelper::getUsuarioId();
        if (!$usuarioId) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        $aviso = Aviso::query()->findOrFail($id);

        $leitura = AvisoLeitura::query()->updateOrCreate(
            [
                'aviso_id' => $aviso->id,
                'usuario_id' => $usuarioId,
            ],
            [
                'lido_em' => now(),
            ]
        );

        $this->auditLogger->logCustom(
            'Aviso',
            $aviso->id,
            'avisos',
            'READ',
            'Aviso lido',
            [],
            ['leitura_id' => $leitura->id]
        );

        return response()->json([
            'data' => [
                'aviso_id' => $aviso->id,
                'usuario_id' => $usuarioId,
                'lido_em' => $leitura->lido_em,
            ],
        ]);
    }

    private function mapAviso(Aviso $aviso): array
    {
        return [
            'id' => $aviso->id,
            'titulo' => $aviso->titulo,
            'conteudo' => $aviso->conteudo,
            'status' => $aviso->status,
            'prioridade' => $aviso->prioridade,
            'pinned' => (bool) $aviso->pinned,
            'publicar_em' => $aviso->publicar_em,
            'expirar_em' => $aviso->expirar_em,
            'criado_por_usuario_id' => $aviso->criado_por_usuario_id,
            'atualizado_por_usuario_id' => $aviso->atualizado_por_usuario_id,
            'created_at' => $aviso->created_at,
            'updated_at' => $aviso->updated_at,
            'lido' => (bool) ($aviso->lido ?? false),
        ];
    }

    private function autorizarVisualizacao(): ?JsonResponse
    {
        if (AuthHelper::hasPermissao('avisos.view') || AuthHelper::hasPermissao('avisos.manage')) {
            return null;
        }

        return response()->json(['message' => 'Sem permissão para visualizar avisos.'], 403);
    }

    private function autorizarGerenciamento(): ?JsonResponse
    {
        if (AuthHelper::hasPermissao('avisos.manage')) {
            return null;
        }

        return response()->json(['message' => 'Sem permissão para gerenciar avisos.'], 403);
    }
}

