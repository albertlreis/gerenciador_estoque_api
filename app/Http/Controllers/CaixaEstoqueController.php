<?php

namespace App\Http\Controllers;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\Estoque;
use App\Models\EstoqueLog;
use App\Models\EstoqueMovimentacao;
use App\Models\ProdutoVariacao;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class CaixaEstoqueController extends Controller
{

    /**
     * GET /v1/estoque/caixa/scan/{codigo}?deposito_id=#
     * Localiza a variação pelo código de barras e retorna dados de exibição.
     */
    public function scan(Request $request, string $codigo): JsonResponse
    {
        $request->validate([
            'deposito_id' => ['nullable', 'integer', 'exists:depositos,id'],
        ]);

        $variacao = ProdutoVariacao::query()
            ->with(['produto:id,nome', 'atributos', 'estoque' => function ($q) use ($request) {
                $q->when($request->integer('deposito_id'), function ($q2, $depId) {
                    $q2->where('id_deposito', $depId);
                });
            }])
            ->where('codigo_barras', $codigo)
            ->first();

        if (!$variacao) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Código de barras não encontrado',
            ], 404);
        }

        $produtoNome = $variacao->relationLoaded('produto') ? ($variacao->produto->nome ?? '') : '';
        $attrs = $variacao->relationLoaded('atributos')
            ? $variacao->atributos->map(fn($a) => "{$a->atributo}: {$a->valor}")->implode(' - ')
            : '';
        $nomeCompleto = trim($produtoNome . ($attrs ? " - $attrs" : ''));

        $estoqueAtual = 0;
        if ($request->integer('deposito_id')) {
            $estoqueAtual = Estoque::query()
                ->where('id_variacao', $variacao->id)
                ->where('id_deposito', $request->integer('deposito_id'))
                ->value('quantidade') ?? 0;
        } else {
            $estoqueAtual = (int) ($variacao->estoque->quantidade ?? 0);
        }

        EstoqueLog::create([
            'id_usuario' => auth()->id(),
            'acao'       => 'scan',
            'payload'    => ['codigo' => $codigo, 'deposito_id' => $request->integer('deposito_id')],
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'sucesso' => true,
            'data' => [
                'variacao_id'   => $variacao->id,
                'produto_id'    => $variacao->produto_id,
                'codigo_barras' => $variacao->codigo_barras,
                'referencia'    => $variacao->referencia,
                'nome'          => $nomeCompleto ?: ($variacao->nome ?? '-'),
                'estoque_atual' => $estoqueAtual,
                'preco'         => $variacao->preco,
            ]
        ]);
    }

    /**
     * POST /v1/estoque/caixa/finalizar
     * {
     *   "tipo": "entrada" | "saida",
     *   "deposito_id": 1,
     *   "observacao": "opcional",
     *   "itens": [{"variacao_id": 10, "quantidade": 2}, ...]
     * }
     */
    public function finalizar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo'         => ['required', Rule::in(['entrada', 'saida'])],
            'deposito_id'  => ['required', 'integer', 'exists:depositos,id'],
            'observacao'   => ['nullable', 'string', 'max:1000'],
            'itens'        => ['required', 'array', 'min:1'],
            'itens.*.variacao_id' => ['required', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.quantidade'  => ['required', 'integer', 'min:1'],
        ]);

        $tipo = $dados['tipo']; // 'entrada' | 'saida'
        $depositoId = (int) $dados['deposito_id'];
        $obs = $dados['observacao'] ?? null;
        $linhas = $dados['itens'];

        // Consolida itens por variação
        $consolidados = [];
        foreach ($linhas as $l) {
            $vid = (int) $l['variacao_id'];
            $qtd = (int) $l['quantidade'];
            $consolidados[$vid] = ($consolidados[$vid] ?? 0) + $qtd;
        }

        $ids = array_keys($consolidados);

        // Validação de estoque para SAÍDA
        $erros = [];
        if ($tipo === EstoqueMovimentacaoTipo::SAIDA->value) {
            $estoques = Estoque::query()
                ->whereIn('id_variacao', $ids)
                ->where('id_deposito', $depositoId)
                ->get(['id', 'id_variacao', 'quantidade'])
                ->keyBy('id_variacao');

            foreach ($consolidados as $vid => $qtd) {
                $qtdAtual = (int) ($estoques[$vid]->quantidade ?? 0);
                if ($qtd > $qtdAtual) {
                    $erros[] = "Variação {$vid}: estoque insuficiente (solicitado {$qtd}, disponível {$qtdAtual})";
                }
            }
        }

        if ($erros) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Não foi possível finalizar o lote.',
                'erros' => $erros,
            ], 422);
        }

        $usuarioId = (int) auth()->id();

        $resultado = DB::transaction(function () use ($tipo, $depositoId, $obs, $consolidados, $usuarioId) {
            $movs = [];

            foreach ($consolidados as $variacaoId => $qtd) {
                /** @var Estoque|null $estoque */
                $estoque = Estoque::query()
                    ->where('id_variacao', $variacaoId)
                    ->where('id_deposito', $depositoId)
                    ->lockForUpdate()
                    ->first();

                if (!$estoque) {
                    $estoque = new Estoque([
                        'id_variacao' => $variacaoId,
                        'id_deposito' => $depositoId,
                        'quantidade'  => 0,
                    ]);
                }

                if ($tipo === EstoqueMovimentacaoTipo::ENTRADA->value) {
                    $estoque->quantidade = (int) $estoque->quantidade + $qtd;
                } else {
                    $estoque->quantidade = (int) $estoque->quantidade - $qtd;
                    if ($estoque->quantidade < 0) {
                        throw new RuntimeException("Estoque negativo para variação {$variacaoId}");
                    }
                }

                $estoque->save();

                $movs[] = EstoqueMovimentacao::create([
                    'id_variacao'         => $variacaoId,
                    'id_deposito_origem'  => $tipo === EstoqueMovimentacaoTipo::SAIDA->value ? $depositoId : null,
                    'id_deposito_destino' => $tipo === EstoqueMovimentacaoTipo::ENTRADA->value ? $depositoId : null,
                    'tipo'                => $tipo === 'entrada'
                        ? EstoqueMovimentacaoTipo::ENTRADA->value
                        : EstoqueMovimentacaoTipo::SAIDA->value,
                    'quantidade'          => $qtd,
                    'observacao'          => $obs,
                    'data_movimentacao'   => now(),
                    'id_usuario'          => $usuarioId,
                ]);
            }

            return $movs;
        });

        EstoqueLog::create([
            'id_usuario' => $usuarioId,
            'acao'       => $tipo, // 'entrada' | 'saida'
            'payload'    => ['deposito_id' => $depositoId, 'total' => array_sum($consolidados), 'itens' => $consolidados],
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Lote finalizado com sucesso.',
            'total_itens' => array_sum($consolidados),
            'movimentacoes' => $resultado,
        ]);
    }

    /**
     * POST /v1/estoque/caixa/transferir
     * {
     *   "deposito_origem_id": 1,
     *   "deposito_destino_id": 2,
     *   "observacao": "opcional",
     *   "itens": [{"variacao_id": 10, "quantidade": 2}, ...]
     * }
     *
     * Registra a transferência movendo saldo e gerando **uma única movimentação**
     * por item com tipo = TRANSFERENCIA e ambos depósitos preenchidos.
     */
    public function transferir(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'deposito_origem_id'  => ['required', 'integer', 'exists:depositos,id'],
            'deposito_destino_id' => ['required', 'integer', 'different:deposito_origem_id', 'exists:depositos,id'],
            'observacao'          => ['nullable', 'string', 'max:1000'],
            'itens'               => ['required', 'array', 'min:1'],
            'itens.*.variacao_id' => ['required', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.quantidade'  => ['required', 'integer', 'min:1'],
        ]);

        $origemId  = (int) $dados['deposito_origem_id'];
        $destinoId = (int) $dados['deposito_destino_id'];
        $obs       = $dados['observacao'] ?? null;
        $usuarioId = (int) auth()->id();

        // Consolida itens
        $consolidados = [];
        foreach ($dados['itens'] as $l) {
            $vid = (int) $l['variacao_id'];
            $qtd = (int) $l['quantidade'];
            $consolidados[$vid] = ($consolidados[$vid] ?? 0) + $qtd;
        }
        $ids = array_keys($consolidados);

        // Validação de estoque na ORIGEM
        $estoquesOrigem = Estoque::query()
            ->whereIn('id_variacao', $ids)
            ->where('id_deposito', $origemId)
            ->get(['id', 'id_variacao', 'quantidade'])
            ->keyBy('id_variacao');

        $erros = [];
        foreach ($consolidados as $vid => $qtd) {
            $disp = (int) ($estoquesOrigem[$vid]->quantidade ?? 0);
            if ($qtd > $disp) {
                $erros[] = "Variação {$vid}: estoque insuficiente na origem (solicitado {$qtd}, disponível {$disp})";
            }
        }
        if ($erros) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Não foi possível transferir.',
                'erros' => $erros,
            ], 422);
        }

        // Move saldo e registra UMA movimentação por item via service
        $movs = DB::transaction(function () use ($origemId, $destinoId, $obs, $usuarioId, $consolidados) {
            $resultado = [];

            foreach ($consolidados as $vid => $qtd) {
                // ORIGEM: decrementa
                /** @var Estoque $origem */
                $origem = Estoque::query()
                    ->where('id_variacao', $vid)
                    ->where('id_deposito', $origemId)
                    ->lockForUpdate()
                    ->first();

                if (!$origem || $origem->quantidade < $qtd) {
                    throw new RuntimeException("Estoque insuficiente na origem para a variação {$vid}");
                }
                $origem->quantidade -= $qtd;
                $origem->save();

                // DESTINO: incrementa (cria se necessário)
                /** @var Estoque|null $destino */
                $destino = Estoque::query()
                    ->where('id_variacao', $vid)
                    ->where('id_deposito', $destinoId)
                    ->lockForUpdate()
                    ->first();

                if (!$destino) {
                    $destino = new Estoque([
                        'id_variacao' => $vid,
                        'id_deposito' => $destinoId,
                        'quantidade'  => 0,
                    ]);
                }
                $destino->quantidade += $qtd;
                $destino->save();

                // Registra **uma** movimentação com enum TRANSFERENCIA
                $obsBase = trim(($obs ?? '') . " [transferência {$origemId}→{$destinoId}]");
                $mov = app(EstoqueMovimentacaoService::class)->registrarTransferencia(
                    variacaoId: $vid,
                    depositoOrigemId: $origemId,
                    depositoDestinoId: $destinoId,
                    quantidade: $qtd,
                    usuarioId: $usuarioId,
                    observacao: $obsBase
                );
                $resultado[] = $mov;

                // LOG detalhado
                EstoqueLog::create([
                    'id_usuario' => $usuarioId,
                    'acao'       => 'transferencia',
                    'payload'    => [
                        'variacao_id'         => $vid,
                        'quantidade'          => $qtd,
                        'deposito_origem_id'  => $origemId,
                        'deposito_destino_id' => $destinoId,
                    ],
                    'ip'         => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }

            return $resultado;
        });

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Transferência concluída com sucesso.',
            'movimentacoes' => $movs, // uma linha por item (tipo = transferencia)
        ]);
    }
}
