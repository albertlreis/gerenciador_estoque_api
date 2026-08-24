<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\StoreProdutoVariacaoOutletRequest;
use App\Http\Resources\ProdutoVariacaoOutletResource;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Models\ProdutoVariacaoOutlet;
use App\Services\ProdutoVariacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProdutoVariacaoOutletController extends Controller
{
    private const PRECO_OUTLET_AUDIT = [
        'label' => 'Alteração de preço pela modal outlet',
        'motivo' => 'Alteração de preço original realizada na modal outlet.',
        'origin' => 'cadastro',
    ];

    private const CUSTO_OUTLET_AUDIT = [
        'label' => 'Alteração de custo pela modal outlet',
        'motivo' => 'Alteração de custo original realizada na modal outlet.',
        'origin' => 'cadastro',
    ];

    public function __construct(private readonly ProdutoVariacaoService $variacaoService)
    {
    }

    /**
     * Lista todos os registros de outlet de uma variação.
     */
    public function index(int $id): JsonResponse
    {
        $variacao = ProdutoVariacao::with([
            'produto',
            'outlets.usuario',
            'outlets.imagemSelecionada',
            'outlets.motivo',
            'outlets.formasPagamento.formaPagamento',
        ])->findOrFail($id);

        return response()->json([
            'variacao_id' => $variacao->id,
            'produto' => optional($variacao->produto)->nome,
            'outlets' => ProdutoVariacaoOutletResource::collection($variacao->outlets),
        ]);
    }

    public function show(int $id, int $outletId): JsonResponse
    {
        $outlet = ProdutoVariacaoOutlet::query()
            ->where('produto_variacao_id', $id)
            ->with([
                'usuario', 'imagemSelecionada', 'motivo',
                'formasPagamento.formaPagamento',
                'variacao.imagem', 'variacao.imagens', 'variacao.estoques',
                'variacao.outlets:id,produto_variacao_id,quantidade',
                'variacao.produto.imagemPrincipal',
            ])
            ->findOrFail($outletId);
        $podeValores = AuthHelper::hasPermissao('produtos.precos_custos');

        return response()->json([
            'data' => [
                'variacao' => array_filter([
                    'id' => (int) $outlet->variacao->id,
                    'preco' => (float) $outlet->variacao->preco,
                    'custo' => $podeValores ? (float) $outlet->variacao->custo : null,
                    'pode_editar_precos_custos' => $podeValores,
                    'estoque_total' => (int) $outlet->variacao->estoques->sum('quantidade'),
                    'outlets' => $outlet->variacao->outlets->map(fn ($item) => [
                        'id' => (int) $item->id,
                        'quantidade' => (int) $item->quantidade,
                    ])->values(),
                    'imagens' => $outlet->variacao->imagens->map(fn ($imagem) => [
                        'id' => (int) $imagem->id,
                        'url' => $imagem->url,
                        'principal' => (bool) ($imagem->principal ?? false),
                        'ordem' => $imagem->ordem ?? 0,
                    ])->values(),
                ], fn ($value, $key) => $key !== 'custo' || $podeValores, ARRAY_FILTER_USE_BOTH),
                'outlet' => (new ProdutoVariacaoOutletResource($outlet))->resolve(request()),
            ],
        ]);
    }

    public function store(StoreProdutoVariacaoOutletRequest $request, int $id): JsonResponse
    {
        $variacao = ProdutoVariacao::with(['estoques', 'outlets'])->findOrFail($id);
        if ($erro = $this->validarFormasPagamentoUnicas($request->input('formas_pagamento', []))) {
            return $erro;
        }
        if ($erro = $this->validarImagemSelecionada($request->input('produto_variacao_imagem_id'), $variacao)) {
            return $erro;
        }

        $estoqueTotal = (int) $variacao->estoques->sum('quantidade');
        $totalOutletJaRegistrado = (int)$variacao->outlets->sum('quantidade');
        $quantidadeNova = (int)$request->quantidade;

        $maxDisponivel = max(0, $estoqueTotal - $totalOutletJaRegistrado);

        if ($quantidadeNova < 1 || $quantidadeNova > $maxDisponivel){
            return response()->json([
                'message' => "Quantidade inválida. Disponível para outlet: $maxDisponivel (Estoque $estoqueTotal − Outlets $totalOutletJaRegistrado)."
            ], 422);
        }

        $existeSimilar = $variacao->outlets->first(function ($outlet) use ($request) {
            return $outlet->motivo_id === $request->motivo_id &&
                $outlet->percentual_desconto == $request->percentual_desconto &&
                $outlet->quantidade == $request->quantidade;
        });

        if ($existeSimilar) {
            return response()->json([
                'message' => 'Já existe um registro outlet semelhante.'
            ], 422);
        }

        $outlet = DB::transaction(function () use ($request, $variacao, $quantidadeNova) {
            $variacao = $this->sincronizarPrecoOriginalSeNecessario($variacao, $request);
            $variacao = $this->sincronizarCustoOriginalSeNecessario($variacao, $request);

            $outlet = new ProdutoVariacaoOutlet([
                'motivo_id' => $request->motivo_id,
                'quantidade' => $quantidadeNova,
                'quantidade_restante' => $quantidadeNova,
                'usuario_id' => Auth::id(),
                'produto_variacao_imagem_id' => $request->input('produto_variacao_imagem_id') ?: null,
            ]);

            $variacao->outlets()->save($outlet);

            foreach ($request->formas_pagamento as $fp) {
                $formaId = $fp['forma_pagamento_id'] ?? null;
                if (!$formaId && !empty($fp['forma_pagamento'])) {
                    $formaId = OutletFormaPagamento::where('slug',$fp['forma_pagamento'])->value('id');
                }

                $outlet->formasPagamento()->create([
                    'forma_pagamento_id' => $formaId,
                    'percentual_desconto' => $fp['percentual_desconto'],
                    'max_parcelas' => $fp['max_parcelas'] ?? null,
                ]);
            }

            return $outlet;
        });

        $outlet->setRelation('variacao', $variacao->loadMissing('imagem', 'produto.imagemPrincipal'));
        $outlet->load(['usuario','imagemSelecionada','motivo','formasPagamento.formaPagamento']);

        return (new ProdutoVariacaoOutletResource($outlet))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id, int $outletId): ProdutoVariacaoOutletResource|JsonResponse
    {
        if ($resposta = $this->autorizarOutlet('editar')) {
            return $resposta;
        }

        $this->normalizarPayload($request);
        if (($request->has('preco_original') || $request->has('custo_original'))
            && !AuthHelper::hasPermissao('produtos.precos_custos')) {
            return response()->json(['message' => 'Sem permissao para alterar preco ou custo.'], 403);
        }

        $variacao = ProdutoVariacao::with(['estoques', 'outlets'])->findOrFail($id);

        $estoqueTotal = (int) $variacao->estoques->sum('quantidade');
        $totalOutros = (int)$variacao->outlets->where('id','!=',$outletId)->sum('quantidade');
        $novaQtd = (int)$request->input('quantidade', 0);

        if ($novaQtd < 1 || ($totalOutros + $novaQtd) > $estoqueTotal) {
            $maxDisponivel = max(0, $estoqueTotal - $totalOutros);
            return response()->json([
                'message' => "A nova quantidade excede o disponível. Máximo permitido: {$maxDisponivel}."
            ], 422);
        }

        /** @var ProdutoVariacaoOutlet $outlet */
        $outlet = ProdutoVariacaoOutlet::where('produto_variacao_id',$id)->findOrFail($outletId);

        $data = $request->validate([
            'quantidade' => 'required|integer|min:1',
            'motivo_id'  => 'required|exists:outlet_motivos,id',
            'produto_variacao_imagem_id' => 'nullable|integer|exists:produto_variacao_imagens,id',
            'preco_original' => 'sometimes|numeric|min:0',
            'custo_original' => 'sometimes|numeric|min:0',
            'motivo_alteracao_preco' => 'nullable|string|max:500',
            'formas_pagamento' => 'sometimes|array|min:1',
            'formas_pagamento.*.id' => 'nullable|integer',
            'formas_pagamento.*.forma_pagamento_id' => 'required_with:formas_pagamento|exists:outlet_formas_pagamento,id',
            'formas_pagamento.*.percentual_desconto'=> 'required_with:formas_pagamento|numeric|min:0|max:100',
            'formas_pagamento.*.max_parcelas'       => 'nullable|integer|min:1|max:36',
        ]);

        if ($request->has('formas_pagamento')) {
            if ($erro = $this->validarFormasPagamentoUnicas($data['formas_pagamento'] ?? [])) {
                return $erro;
            }
        }
        if ($request->has('produto_variacao_imagem_id')) {
            if ($erro = $this->validarImagemSelecionada($data['produto_variacao_imagem_id'] ?? null, $variacao)) {
                return $erro;
            }
        }

        DB::transaction(function () use ($request, $variacao, $outlet, $data): void {
            $variacao = $this->sincronizarPrecoOriginalSeNecessario($variacao, $request);
            $this->sincronizarCustoOriginalSeNecessario($variacao, $request);

            $updates = [
                'quantidade' => $data['quantidade'],
                'quantidade_restante' => max(
                    0,
                    (int) $data['quantidade'] - max(0, (int) $outlet->quantidade - (int) $outlet->quantidade_restante)
                ),
                'motivo_id'  => (int)$data['motivo_id'],
            ];

            if (array_key_exists('produto_variacao_imagem_id', $data)) {
                $updates['produto_variacao_imagem_id'] = $data['produto_variacao_imagem_id'] ?: null;
            }

            $outlet->update($updates);

            if ($request->has('formas_pagamento')) {
                $this->sincronizarFormasPagamento($outlet, $data['formas_pagamento']);
            }
        });

        $outlet->setRelation('variacao', $variacao->loadMissing('imagem', 'produto.imagemPrincipal'));
        $outlet->load(['usuario','imagemSelecionada','motivo','formasPagamento.formaPagamento']);
        return new ProdutoVariacaoOutletResource($outlet);
    }

    public function destroy(int $id, int $outletId): JsonResponse
    {
        if ($resposta = $this->autorizarOutlet('excluir')) {
            return $resposta;
        }

        $outlet = ProdutoVariacaoOutlet::where('produto_variacao_id', $id)->findOrFail($outletId);
        $outlet->delete();

        return response()->json(['message' => 'Outlet removido com sucesso']);
    }


    private function autorizarOutlet(string $acao): ?JsonResponse
    {
        $mapa = [
            'criar' => ['produtos.outlet.cadastrar', 'produtos.gerenciar'],
            'editar' => ['produtos.outlet.editar', 'produtos.gerenciar'],
            'excluir' => ['produtos.outlet.excluir', 'produtos.gerenciar'],
        ];

        $permissoes = $mapa[$acao] ?? [];

        foreach ($permissoes as $permissao) {
            if (AuthHelper::hasPermissao($permissao)) {
                return null;
            }
        }

        return response()->json(['message' => 'Sem permiss??o para esta a????o.'], 403);
    }

    private function validarFormasPagamentoUnicas(array $formasPagamento): ?JsonResponse
    {
        $vistos = [];

        foreach ($formasPagamento as $fp) {
            $formaId = $fp['forma_pagamento_id'] ?? null;
            if (!$formaId && !empty($fp['forma_pagamento'])) {
                $formaId = OutletFormaPagamento::where('slug', $fp['forma_pagamento'])->value('id');
            }

            $desconto = isset($fp['percentual_desconto'])
                ? number_format((float) $fp['percentual_desconto'], 2, '.', '')
                : '';
            $parcelas = $fp['max_parcelas'] ?? null;
            $chave = implode('|', [(string) $formaId, $desconto, $parcelas === null ? 'null' : (string) $parcelas]);

            if (isset($vistos[$chave])) {
                return response()->json([
                    'message' => 'Forma de pagamento duplicada com o mesmo desconto e parcelas.',
                ], 422);
            }

            $vistos[$chave] = true;
        }

        return null;
    }

    private function validarImagemSelecionada(mixed $imagemId, ProdutoVariacao $variacao): ?JsonResponse
    {
        if ($imagemId === null || $imagemId === '') {
            return null;
        }

        $pertence = ProdutoVariacaoImagem::query()
            ->where('id', (int) $imagemId)
            ->where('id_variacao', $variacao->id)
            ->exists();

        if ($pertence) {
            return null;
        }

        return response()->json([
            'message' => 'A imagem selecionada nao pertence a esta variacao.',
            'errors' => [
                'produto_variacao_imagem_id' => ['A imagem selecionada nao pertence a esta variacao.'],
            ],
        ], 422);
    }

    private function sincronizarPrecoOriginalSeNecessario(ProdutoVariacao $variacao, Request $request): ProdutoVariacao
    {
        if (!$request->has('preco_original')) {
            return $variacao;
        }

        $precoOriginal = round((float) $request->input('preco_original'), 2);
        $precoAtual = round((float) ($variacao->preco ?? 0), 2);

        if ($precoOriginal === $precoAtual) {
            return $variacao;
        }

        $motivo = trim((string) $request->input('motivo_alteracao_preco', ''));
        if ($motivo === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'variacao.motivo_alteracao_preco' => ['Informe o motivo da alteracao do preco global.'],
            ]);
        }

        $audit = array_merge(self::PRECO_OUTLET_AUDIT, [
            'motivo' => $motivo,
            'origin' => 'outlet',
        ]);

        return $this->variacaoService->salvarComAuditoria(
            $variacao,
            ['preco' => $precoOriginal],
            $audit,
            self::PRECO_OUTLET_AUDIT['label']
        );
    }

    private function sincronizarCustoOriginalSeNecessario(ProdutoVariacao $variacao, Request $request): ProdutoVariacao
    {
        if (!$request->has('custo_original')) {
            return $variacao;
        }

        $custoOriginal = round((float) $request->input('custo_original'), 2);
        $custoAtual = round((float) ($variacao->custo ?? 0), 2);

        if ($custoOriginal === $custoAtual) {
            return $variacao;
        }

        return $this->variacaoService->salvarComAuditoria(
            $variacao,
            ['custo' => $custoOriginal],
            array_merge(self::CUSTO_OUTLET_AUDIT, ['origin' => 'outlet']),
            self::CUSTO_OUTLET_AUDIT['label']
        );
    }

    private function normalizarPayload(Request $request): void
    {
        $outlet = is_array($request->input('outlet')) ? $request->input('outlet') : [];
        $variacao = is_array($request->input('variacao')) ? $request->input('variacao') : [];
        $globais = [];
        foreach (['preco' => 'preco_original', 'custo' => 'custo_original', 'motivo_alteracao_preco' => 'motivo_alteracao_preco'] as $origem => $destino) {
            if (array_key_exists($origem, $variacao)) {
                $globais[$destino] = $variacao[$origem];
            }
        }
        $request->merge(array_merge($outlet, $globais));
    }

    private function sincronizarFormasPagamento(ProdutoVariacaoOutlet $outlet, array $formas): void
    {
        $idsMantidos = [];
        foreach ($formas as $forma) {
            $id = isset($forma['id']) ? (int) $forma['id'] : null;
            $dados = [
                'forma_pagamento_id' => (int) $forma['forma_pagamento_id'],
                'percentual_desconto' => $forma['percentual_desconto'],
                'max_parcelas' => $forma['max_parcelas'] ?? null,
            ];

            if ($id) {
                $condicao = $outlet->formasPagamento()->whereKey($id)->firstOrFail();
                $condicao->update($dados);
            } else {
                $condicao = $outlet->formasPagamento()->create($dados);
            }
            $idsMantidos[] = $condicao->id;
        }

        $outlet->formasPagamento()->whereNotIn('id', $idsMantidos)->delete();
    }

}
