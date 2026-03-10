<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\ProdutoConjuntoHeroUploadRequest;
use App\Http\Requests\StoreProdutoConjuntoRequest;
use App\Http\Requests\UpdateProdutoConjuntoRequest;
use App\Models\ProdutoConjunto;
use App\Models\ProdutoVariacao;
use App\Services\AuditoriaEventoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProdutoConjuntoController extends Controller
{
    public function __construct(private readonly AuditoriaEventoService $auditoria)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ProdutoConjunto::query()
            ->with([
                'principalVariacao:id,produto_id,referencia,nome,preco',
                'principalVariacao.produto:id,nome',
                'itens:id,produto_conjunto_id,produto_variacao_id,label,ordem',
                'itens.variacao:id,produto_id,referencia,nome,preco',
                'itens.variacao.produto:id,nome',
            ])
            ->orderBy('nome');

        if ($request->filled('ativo')) {
            $query->where('ativo', $request->boolean('ativo'));
        }

        if ($request->filled('search')) {
            $busca = '%' . trim((string) $request->input('search')) . '%';
            $query->where('nome', 'like', $busca);
        }

        return response()->json($query->get()->map(fn (ProdutoConjunto $conjunto) => $this->toPayload($conjunto))->all());
    }

    public function store(StoreProdutoConjuntoRequest $request): JsonResponse
    {
        if ($resposta = $this->autorizarConjunto('criar')) {
            return $resposta;
        }

        $conjunto = DB::transaction(function () use ($request): ProdutoConjunto {
            $payload = $request->validated();

            $conjunto = ProdutoConjunto::create([
                'nome' => $payload['nome'],
                'descricao' => $payload['descricao'] ?? null,
                'hero_image_path' => null,
                'preco_modo' => $payload['preco_modo'],
                'principal_variacao_id' => $payload['principal_variacao_id'] ?? null,
                'ativo' => $payload['ativo'] ?? true,
            ]);

            $itensResumo = $this->syncItens($conjunto, $payload['itens'] ?? []);

            $this->auditoria->registrar(
                module: 'produto_conjuntos',
                action: 'create',
                label: 'Conjunto criado',
                auditable: $conjunto,
                metadata: [
                    'preco_modo' => $conjunto->preco_modo,
                    'principal_variacao_id' => $conjunto->principal_variacao_id,
                    'quantidade_itens' => count($itensResumo),
                    'variacao_ids' => array_column($itensResumo, 'produto_variacao_id'),
                    'itens_resumo' => $itensResumo,
                ]
            );

            return $conjunto->fresh($this->relations());
        });

        return response()->json($this->toPayload($conjunto), 201);
    }

    public function show(ProdutoConjunto $produtoConjunto): JsonResponse
    {
        $produtoConjunto->load($this->relations());

        return response()->json($this->toPayload($produtoConjunto));
    }

    public function update(UpdateProdutoConjuntoRequest $request, ProdutoConjunto $produtoConjunto): JsonResponse
    {
        if ($resposta = $this->autorizarConjunto('editar')) {
            return $resposta;
        }

        $conjunto = DB::transaction(function () use ($request, $produtoConjunto): ProdutoConjunto {
            $payload = $request->validated();
            $camposAuditaveis = ['nome', 'descricao', 'ativo', 'preco_modo', 'principal_variacao_id', 'hero_image_path'];
            $before = $produtoConjunto->only($camposAuditaveis);

            $updates = [];
            foreach (['nome', 'descricao', 'ativo', 'preco_modo', 'principal_variacao_id'] as $campo) {
                if (array_key_exists($campo, $payload)) {
                    $updates[$campo] = $payload[$campo];
                }
            }

            if (!empty($updates)) {
                $produtoConjunto->fill($updates);
            }

            $itensResumo = $this->listarItensResumo($produtoConjunto);
            $itensMudaram = false;
            if (array_key_exists('itens', $payload)) {
                $novosItensResumo = $this->normalizarItensResumo($payload['itens']);
                $itensMudaram = $novosItensResumo !== $itensResumo;
                $itensResumo = $this->syncItens($produtoConjunto, $payload['itens']);
            }

            $mudancas = [];
            if ($produtoConjunto->isDirty()) {
                $produtoConjunto->save();

                foreach ($camposAuditaveis as $campo) {
                    if (!$produtoConjunto->wasChanged($campo)) {
                        continue;
                    }

                    $mudancas[] = [
                        'campo' => $campo,
                        'old' => $before[$campo] ?? null,
                        'new' => $produtoConjunto->{$campo},
                    ];
                }
            }

            if (!empty($mudancas) || $itensMudaram) {
                $this->auditoria->registrar(
                    module: 'produto_conjuntos',
                    action: 'update',
                    label: 'Conjunto atualizado',
                    auditable: $produtoConjunto,
                    mudancas: $mudancas,
                    metadata: [
                        'preco_modo' => $produtoConjunto->preco_modo,
                        'principal_variacao_id' => $produtoConjunto->principal_variacao_id,
                        'quantidade_itens' => count($itensResumo),
                        'variacao_ids' => array_column($itensResumo, 'produto_variacao_id'),
                        'itens_resumo' => $itensResumo,
                    ]
                );
            }

            return $produtoConjunto->fresh($this->relations());
        });

        return response()->json($this->toPayload($conjunto));
    }

    public function destroy(ProdutoConjunto $produtoConjunto): JsonResponse
    {
        if ($resposta = $this->autorizarConjunto('excluir')) {
            return $resposta;
        }

        DB::transaction(function () use ($produtoConjunto): void {
            $itensResumo = $this->listarItensResumo($produtoConjunto);
            $heroAnterior = $produtoConjunto->hero_image_path;

            $this->auditoria->registrar(
                module: 'produto_conjuntos',
                action: 'delete',
                label: 'Conjunto removido',
                auditable: $produtoConjunto,
                mudancas: [[
                    'campo' => 'ativo',
                    'old' => $produtoConjunto->ativo,
                    'new' => null,
                ]],
                metadata: [
                    'preco_modo' => $produtoConjunto->preco_modo,
                    'principal_variacao_id' => $produtoConjunto->principal_variacao_id,
                    'quantidade_itens' => count($itensResumo),
                    'variacao_ids' => array_column($itensResumo, 'produto_variacao_id'),
                    'itens_resumo' => $itensResumo,
                    'hero_image_path' => $heroAnterior,
                ]
            );

            $produtoConjunto->delete();

            if ($heroAnterior) {
                Storage::disk('public')->delete($heroAnterior);
            }
        });

        return response()->json(null, 204);
    }

    public function uploadHero(ProdutoConjuntoHeroUploadRequest $request, ProdutoConjunto $produtoConjunto): JsonResponse
    {
        if ($resposta = $this->autorizarConjunto('editar')) {
            return $resposta;
        }

        $conjunto = DB::transaction(function () use ($request, $produtoConjunto): ProdutoConjunto {
            $oldPath = $produtoConjunto->hero_image_path;
            $file = $request->file('file');
            $path = $file->storeAs('conjuntos', Str::uuid() . '.' . $file->getClientOriginalExtension(), 'public');

            $produtoConjunto->hero_image_path = $path;
            $produtoConjunto->save();

            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }

            $this->auditoria->registrar(
                module: 'produto_conjuntos',
                action: 'upload_hero',
                label: 'Imagem do conjunto atualizada',
                auditable: $produtoConjunto,
                mudancas: [[
                    'campo' => 'hero_image_path',
                    'old' => $oldPath,
                    'new' => $path,
                ]],
                metadata: [
                    'preco_modo' => $produtoConjunto->preco_modo,
                    'principal_variacao_id' => $produtoConjunto->principal_variacao_id,
                    'quantidade_itens' => $produtoConjunto->itens()->count(),
                    'path_anterior' => $oldPath,
                    'path_novo' => $path,
                ]
            );

            return $produtoConjunto->fresh($this->relations());
        });

        return response()->json([
            'data' => $this->toPayload($conjunto),
            'url' => Storage::disk('public')->url((string) $conjunto->hero_image_path),
        ]);
    }

    private function syncItens(ProdutoConjunto $conjunto, array $itens): array
    {
        $itensNormalizados = collect($this->normalizarItensResumo($itens));

        $conjunto->itens()->delete();

        if ($itensNormalizados->isNotEmpty()) {
            $conjunto->itens()->createMany($itensNormalizados->all());
        }

        return $itensNormalizados->all();
    }

    private function listarItensResumo(ProdutoConjunto $conjunto): array
    {
        return $conjunto->itens()
            ->orderBy('ordem')
            ->orderBy('id')
            ->get(['produto_variacao_id', 'label', 'ordem'])
            ->map(fn ($item) => [
                'produto_variacao_id' => (int) $item->produto_variacao_id,
                'label' => $item->label !== null ? trim((string) $item->label) : null,
                'ordem' => (int) $item->ordem,
            ])
            ->all();
    }

    private function normalizarItensResumo(array $itens): array
    {
        return collect($itens)
            ->map(function (array $item): array {
                $label = isset($item['label']) ? trim((string) $item['label']) : null;

                return [
                    'produto_variacao_id' => (int) $item['produto_variacao_id'],
                    'label' => $label !== '' ? $label : null,
                    'ordem' => (int) ($item['ordem'] ?? 0),
                ];
            })
            ->sortBy(fn (array $item) => [$item['ordem'], $item['produto_variacao_id'], $item['label'] ?? ''])
            ->values()
            ->all();
    }

    private function toPayload(ProdutoConjunto $conjunto): array
    {
        return [
            'id' => $conjunto->id,
            'nome' => $conjunto->nome,
            'descricao' => $conjunto->descricao,
            'hero_image_path' => $conjunto->hero_image_path,
            'hero_image_url' => $conjunto->hero_image_path ? Storage::disk('public')->url($conjunto->hero_image_path) : null,
            'preco_modo' => $conjunto->preco_modo,
            'principal_variacao_id' => $conjunto->principal_variacao_id,
            'ativo' => (bool) $conjunto->ativo,
            'principal_variacao' => $conjunto->principalVariacao ? [
                'id' => $conjunto->principalVariacao->id,
                'referencia' => $conjunto->principalVariacao->referencia,
                'nome' => $conjunto->principalVariacao->nome,
                'produto_nome' => $conjunto->principalVariacao->produto?->nome,
                'preco' => $conjunto->principalVariacao->preco,
            ] : null,
            'itens' => $conjunto->itens->map(function ($item) {
                /** @var ProdutoVariacao|null $variacao */
                $variacao = $item->variacao;

                return [
                    'id' => $item->id,
                    'produto_variacao_id' => $item->produto_variacao_id,
                    'label' => $item->label,
                    'ordem' => $item->ordem,
                    'variacao' => $variacao ? [
                        'id' => $variacao->id,
                        'referencia' => $variacao->referencia,
                        'nome' => $variacao->nome,
                        'produto_nome' => $variacao->produto?->nome,
                        'preco' => $variacao->preco,
                    ] : null,
                ];
            })->values()->all(),
            'created_at' => $conjunto->created_at,
            'updated_at' => $conjunto->updated_at,
        ];
    }

    private function relations(): array
    {
        return [
            'principalVariacao:id,produto_id,referencia,nome,preco',
            'principalVariacao.produto:id,nome',
            'itens:id,produto_conjunto_id,produto_variacao_id,label,ordem',
            'itens.variacao:id,produto_id,referencia,nome,preco',
            'itens.variacao.produto:id,nome',
        ];
    }

    private function autorizarConjunto(string $acao): ?JsonResponse
    {
        $mapa = [
            'criar' => ['produto_conjuntos.criar', 'produtos.editar', 'produtos.gerenciar'],
            'editar' => ['produto_conjuntos.editar', 'produtos.editar', 'produtos.gerenciar'],
            'excluir' => ['produto_conjuntos.excluir', 'produtos.excluir', 'produtos.gerenciar'],
        ];

        foreach ($mapa[$acao] ?? [] as $permissao) {
            if (AuthHelper::hasPermissao($permissao)) {
                return null;
            }
        }

        return response()->json(['message' => 'Sem permissão para esta ação.'], 403);
    }
}
