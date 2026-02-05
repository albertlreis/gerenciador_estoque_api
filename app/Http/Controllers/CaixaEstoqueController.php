<?php

namespace App\Http\Controllers;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\Estoque;
use App\Models\EstoqueLog;
use App\Models\ProdutoVariacao;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
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

        $usuarioId = (int) auth()->id();

        try {
            $payload = [
                'tipo' => $tipo,
                'deposito_origem_id' => $tipo === EstoqueMovimentacaoTipo::SAIDA->value ? $depositoId : null,
                'deposito_destino_id' => $tipo === EstoqueMovimentacaoTipo::ENTRADA->value ? $depositoId : null,
                'observacao' => $obs,
                'itens' => $linhas,
            ];

            $resultado = app(EstoqueMovimentacaoService::class)->registrarMovimentacaoLote($payload, $usuarioId);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Não foi possível finalizar o lote.',
                'erros' => [$e->getMessage()],
            ], 422);
        }

        EstoqueLog::create([
            'id_usuario' => $usuarioId,
            'acao'       => $tipo, // 'entrada' | 'saida'
            'payload'    => ['deposito_id' => $depositoId, 'total' => $resultado['total_pecas'] ?? null],
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Lote finalizado com sucesso.',
            'total_itens' => $resultado['total_itens'] ?? null,
            'movimentacoes' => $resultado['movimentacoes'] ?? [],
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

        try {
            $payload = [
                'tipo' => 'transferencia',
                'deposito_origem_id' => $origemId,
                'deposito_destino_id' => $destinoId,
                'observacao' => $obs,
                'itens' => $dados['itens'],
            ];

            $resultado = app(EstoqueMovimentacaoService::class)->registrarMovimentacaoLote($payload, $usuarioId);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Não foi possível transferir.',
                'erros' => [$e->getMessage()],
            ], 422);
        }

        EstoqueLog::create([
            'id_usuario' => $usuarioId,
            'acao'       => 'transferencia',
            'payload'    => [
                'deposito_origem_id'  => $origemId,
                'deposito_destino_id' => $destinoId,
                'total_pecas' => $resultado['total_pecas'] ?? null,
            ],
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Transferência concluída com sucesso.',
            'movimentacoes' => $resultado['movimentacoes'] ?? [],
            'transferencia_id' => $resultado['transferencia_id'] ?? null,
            'transferencia_pdf' => $resultado['transferencia_pdf'] ?? null,
        ]);
    }
}
