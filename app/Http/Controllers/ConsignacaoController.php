<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\ConsignacaoDetalhadaResource;
use App\Http\Resources\ConsignacaoResource;
use App\Models\AcessoUsuario;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\ConsignacaoDevolucao;
use App\Models\Pedido;
use App\Services\EstoqueMovimentacaoService;
use App\Services\PdfImageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ConsignacaoController extends Controller
{

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'data_ini' => 'nullable|date_format:Y-m-d',
            'data_fim' => 'nullable|date_format:Y-m-d',
        ]);

        $query = Consignacao::with([
            'pedido.cliente:id,nome',
            'pedido.usuario:id,nome',
            'pedido.parceiro:id,nome',
            'pedido.statusAtual',
            'produtoVariacao.produto',
        ])
            ->withSum(['devolucoes as devolvido_total' => fn ($q) => $q->whereNull('cancelada_em')], 'quantidade');

        if (!AuthHelper::hasPermissao('consignacoes.visualizar.todos')) {
            $query->whereHas('pedido', function ($q) {
                $q->where('id_usuario', AuthHelper::getUsuarioId());
            });
        }

        if ($request->filled('cliente_id')) {
            $query->whereHas('pedido.cliente', function ($q) use ($request) {
                $q->where('id', $request->cliente_id);
            });
        }

        if ($request->filled('produto')) {
            $produto = $request->produto;
            $query->whereHas('produtoVariacao.produto', function ($q) use ($produto) {
                $q->where('nome', 'like', "%$produto%");
            });
        }

        if ($request->filled('vencimento_proximo')) {
            $query->whereDate('prazo_resposta', '<=', now()->addDays(3));
        }

        if ($request->filled('vendedor_id')) {
            $query->whereHas('pedido', function ($q) use ($request) {
                $q->where('id_usuario', $request->vendedor_id);
            });
        }

        if ($request->filled('data_ini')) {
            $query->whereDate('prazo_resposta', '>=', $request->input('data_ini'));
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('prazo_resposta', '<=', $request->input('data_fim'));
        }

        if (in_array($request->status, ['pendente', 'vencido'])) {
            $query->where('status', 'pendente');
        }

        $query->orderBy('prazo_resposta');

        $consignacoes = $query->get();

        $agrupadas = $consignacoes->groupBy('pedido_id')->map(function ($grupo) {
            $primeira = $grupo->first();
            $primeira->todas_consignacoes = $grupo;
            return $primeira;
        });

        if ($request->filled('status')) {
            $statusDesejado = $request->status;
            $hoje = now();

            $agrupadas = $agrupadas->filter(function ($consignacao) use ($statusDesejado, $hoje) {
                $status = 'pendente';
                $temPendente = false;
                $temComprado = false;
                $temDevolvido = false;

                foreach ($consignacao->todas_consignacoes as $item) {
                    if ($item->status === 'pendente') {
                        if ($item->prazo_resposta && $item->prazo_resposta->lt($hoje)) {
                            $status = 'vencido';
                            break;
                        }
                        $temPendente = true;
                    }
                    if ($item->status === 'comprado') $temComprado = true;
                    if ($item->status === 'devolvido') $temDevolvido = true;
                }

                if ($temPendente) {
                    $status = 'pendente';
                    if ($consignacao->todas_consignacoes->where('status', 'pendente')->pluck('prazo_resposta')->contains(fn($p) => $p && $p->lt($hoje))) {
                        $status = 'vencido';
                    }
                } elseif ($temComprado && $temDevolvido) {
                    $status = 'parcial';
                } elseif ($temComprado) {
                    $status = 'comprado';
                } elseif ($temDevolvido) {
                    $status = 'devolvido';
                }

                return $status === $statusDesejado;
            });
        }

        $pagina = $request->get('page', 1);
        $porPagina = $request->get('per_page', 10);
        $paginado = new LengthAwarePaginator(
            $agrupadas->forPage($pagina, $porPagina)->values(),
            $agrupadas->count(),
            $porPagina,
            $pagina
        );

        return ConsignacaoResource::collection($paginado);
    }

    public function porPedido($pedido_id): JsonResponse
    {
        $consignacoes = Consignacao::with([
            'pedido.cliente',
            'produtoVariacao.produto.imagemPrincipal',
            'produtoVariacao.atributos',
            'devolucoes.usuario',
            'devolucoes.canceladaPor',
        ])
            ->where('pedido_id', $pedido_id)
            ->get();

        if ($consignacoes->isEmpty()) {
            return response()->json(['erro' => 'Nenhuma consignação encontrada para este pedido.'], 404);
        }

        $pedido = $consignacoes->first()->pedido;

        return response()->json([
            'pedido' => [
                'id' => $pedido->id,
                'numero_externo' => $pedido->numero_externo,
                'cliente' => $pedido->cliente->nome ?? '-',
                'data_envio' => optional($pedido->data_envio)->format('d/m/Y'),
            ],
            'consignacoes' => ConsignacaoDetalhadaResource::collection($consignacoes)
        ]);
    }

    public function show($id): JsonResponse
    {
        $consignacao = Consignacao::with([
            'pedido.cliente',
            'pedido.usuario',
            'produtoVariacao.produto.imagemPrincipal',
            'produtoVariacao.atributos',
            'devolucoes',
            'devolucoes.usuario',
            'devolucoes.canceladaPor'
        ])->findOrFail($id);

        return response()->json(new ConsignacaoDetalhadaResource($consignacao));
    }

    public function atualizarStatus($id, Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:comprado,devolvido',
        ]);

        $consignacao = Consignacao::findOrFail($id);

        // Se já finalizada, não permitir alteração
        if (in_array($consignacao->status, ['comprado', 'devolvido'])) {
            return response()->json(['erro' => 'Consignação já finalizada.'], 422);
        }

        $consignacao->status = $request->status;
        $consignacao->data_resposta = now();
        $consignacao->save();

        return response()->json([
            'mensagem' => 'Status atualizado com sucesso.',
            'consignacao' => new ConsignacaoDetalhadaResource($consignacao->fresh(['pedido.cliente', 'produtoVariacao.produto'])),
        ]);
    }

    public function vencendo(): JsonResponse
    {
        $query = Consignacao::with(['pedido.cliente', 'produtoVariacao.produto'])
            ->where('status', 'pendente')
            ->whereDate('prazo_resposta', '<=', now()->addDays(2))
            ->orderBy('prazo_resposta');

        if (!AuthHelper::hasPermissao('consignacoes.vencendo.todos')) {
            $query->whereHas('pedido', function ($q) {
                $q->where('id_usuario', AuthHelper::getUsuarioId());
            });
        }

        $consignacoes = $query->get();

        return response()->json(ConsignacaoResource::collection($consignacoes));
    }

    public function registrarDevolucao($id, Request $request): JsonResponse
    {
        $request->validate([
            'quantidade' => 'required|integer|min:1',
            'observacoes' => 'nullable|string',
            'deposito_id' => 'required|exists:depositos,id',
        ]);

        $consignacao = Consignacao::with('devolucoes')->findOrFail($id);

        if ($consignacao->status !== 'pendente') {
            return response()->json(['erro' => 'Não é possível registrar devolução para consignação finalizada.'], 422);
        }

        $quantidadeDevolvida = $consignacao->quantidadeDevolvida();
        $restante = $consignacao->quantidade - $quantidadeDevolvida;

        if ($request->quantidade > $restante) {
            return response()->json(['erro' => 'Quantidade devolvida excede o restante.'], 422);
        }

        $usuarioId = AuthHelper::getUsuarioId();

        DB::transaction(function () use ($consignacao, $request, $quantidadeDevolvida, $usuarioId) {
            $movimentacao = app(EstoqueMovimentacaoService::class)->registrarMovimentacaoManual([
                'id_variacao'         => (int) $consignacao->produto_variacao_id,
                'id_deposito_origem'  => null,
                'id_deposito_destino' => (int) $request->deposito_id,
                'tipo'                => 'consignacao_devolucao',
                'quantidade'          => (int) $request->quantidade,
                'observacao'          => $request->observacoes,
                'data_movimentacao'   => now(),
                'ref_type'            => 'consignacao',
                'ref_id'              => (int) $consignacao->id,
                'pedido_id'           => (int) $consignacao->pedido_id,
            ], (int) $usuarioId);

            $consignacao->devolucoes()->create([
                'quantidade' => $request->quantidade,
                'observacoes' => $request->observacoes,
                'usuario_id' => AuthHelper::getUsuarioId(),
                'estoque_movimentacao_id' => $movimentacao->id,
                'deposito_id' => $request->deposito_id,
            ]);

            $novaQtdDevolvida = $quantidadeDevolvida + $request->quantidade;
            if ($novaQtdDevolvida >= $consignacao->quantidade) {
                $consignacao->status = 'devolvido';
                $consignacao->data_resposta = now();
                $consignacao->save();
            }
        });

        return response()->json(['mensagem' => 'Devolução registrada com sucesso.']);
    }

    public function cancelarDevolucao(int $consignacaoId, int $devolucaoId, Request $request): JsonResponse
    {
        $request->validate([
            'motivo' => 'nullable|string',
        ]);

        $usuarioId = AuthHelper::getUsuarioId();
        $consignacao = Consignacao::with('devolucoes')->findOrFail($consignacaoId);
        $devolucao = ConsignacaoDevolucao::query()
            ->where('consignacao_id', $consignacao->id)
            ->findOrFail($devolucaoId);

        if ($devolucao->cancelada_em) {
            return response()->json(['erro' => 'Devolucao ja cancelada.'], 422);
        }

        if (!$devolucao->estoque_movimentacao_id) {
            return response()->json([
                'erro' => 'Esta devolucao nao possui movimentacao vinculada. Faca a correcao manual do estoque antes de cancelar.',
            ], 422);
        }

        DB::transaction(function () use ($consignacao, $devolucao, $request, $usuarioId) {
            app(EstoqueMovimentacaoService::class)->estornarMovimentacao(
                (int) $devolucao->estoque_movimentacao_id,
                (int) $usuarioId,
                "Cancelamento da devolucao #{$devolucao->id} da consignacao #{$consignacao->id}"
            );

            $devolucao->update([
                'cancelada_em' => now(),
                'cancelada_por' => $usuarioId,
                'motivo_cancelamento' => $request->motivo,
            ]);

            $this->recalcularStatusConsignacao($consignacao->fresh('devolucoes'));
        });

        return response()->json(['mensagem' => 'Devolucao cancelada com sucesso.']);
    }

    public function cancelarVenda(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'motivo' => 'nullable|string',
        ]);

        $consignacao = Consignacao::findOrFail($id);
        if ($consignacao->status !== 'comprado') {
            return response()->json(['erro' => 'Apenas itens vendidos podem ter a venda cancelada.'], 422);
        }

        $consignacao->status = 'pendente';
        $consignacao->data_resposta = null;
        $consignacao->save();

        logAuditoria('consignacao_cancelamento_venda', "Venda da consignacao #{$consignacao->id} cancelada.", [
            'acao' => 'cancelar_venda_item',
            'consignacao_id' => $consignacao->id,
            'pedido_id' => $consignacao->pedido_id,
            'motivo' => $request->motivo,
        ], $consignacao);

        return response()->json([
            'mensagem' => 'Venda da consignacao cancelada com sucesso.',
            'consignacao' => new ConsignacaoDetalhadaResource($consignacao->fresh([
                'pedido.cliente',
                'produtoVariacao.produto',
                'devolucoes.usuario',
                'devolucoes.canceladaPor',
            ])),
        ]);
    }

    /**
     * Gera o PDF do roteiro de consignação.
     *
     * Inclui: parceiro, imagem do produto, e localização de estoque.
     */
    public function gerarPdf(int $id, Request $request): Response
    {
        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',
            'statusAtual',
            'consignacoes.deposito',
            'consignacoes.produtoVariacao.imagem',
            'consignacoes.produtoVariacao.produto.imagemPrincipal',
            'consignacoes.produtoVariacao.produto',
            'consignacoes.produtoVariacao.atributos',
            'consignacoes.produtoVariacao.estoquesComLocalizacao',
        ])->findOrFail($id);

        $pdfImageService = app(PdfImageService::class);
        $pedido->consignacoes->each(function ($consignacao) use ($pdfImageService) {
            $consignacao->setAttribute(
                'pdf_imagem_data_uri',
                $pdfImageService->fromProdutoVariacao($consignacao->produtoVariacao)
            );
        });

        $grupos = $pedido->consignacoes->groupBy(fn($item) => $item->deposito->nome ?? 'Sem depósito');
        $tipoRoteiro = $this->normalizarTipoRoteiro($request->query('tipo_roteiro'));
        $isDevolucao = $tipoRoteiro
            ? $tipoRoteiro === 'devolucao'
            : $this->isRoteiroDeDevolucao($pedido);
        $tituloRoteiro = $isDevolucao ? 'Roteiro de devolução' : 'Roteiro de consignação';
        $filename = $isDevolucao
            ? "roteiro-de-devolucao-{$id}.pdf"
            : "roteiro-de-consignacao-{$id}.pdf";

        logAuditoria('consignacao_pdf', 'Geração de PDF de roteiro de consignação', [
            'acao' => 'gerar_pdf',
            'pedido_id' => $id,
            'documento' => $tituloRoteiro,
        ]);

        // Permitir imagens locais/externas
        Pdf::setOptions(['isRemoteEnabled' => true]);

        $pdf = Pdf::loadView('exports.roteiro-consignacao', [
            'pedido'     => $pedido,
            'grupos'     => $grupos,
            'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
            'tituloRoteiro' => $tituloRoteiro,
        ])->setPaper('a4');

        return $pdf->download($filename);
    }

    private function isRoteiroDeDevolucao(Pedido $pedido): bool
    {
        $statusAtual = $pedido->statusAtual?->status?->value ?? $pedido->statusAtual?->status;
        if ($statusAtual === 'devolucao_consignacao') {
            return true;
        }

        if ($pedido->consignacoes->isEmpty()) {
            return false;
        }

        return $pedido->consignacoes->every(function ($item) {
            $status = strtolower((string) ($item->status ?? ''));
            return in_array($status, ['devolvido', 'comprado', 'finalizado'], true);
        });
    }

    private function recalcularStatusConsignacao(Consignacao $consignacao): void
    {
        $devolvido = $consignacao->quantidadeDevolvida();

        if ($devolvido >= (int) $consignacao->quantidade) {
            $consignacao->status = 'devolvido';
            $consignacao->data_resposta = $consignacao->data_resposta ?: now();
        } elseif ($consignacao->status === 'devolvido') {
            $consignacao->status = 'pendente';
            $consignacao->data_resposta = null;
        }

        $consignacao->save();
    }

    private function normalizarTipoRoteiro(mixed $tipo): ?string
    {
        if ($tipo === null || $tipo === '') {
            return null;
        }

        $tipo = strtolower((string) $tipo);
        abort_unless(in_array($tipo, ['consignacao', 'devolucao'], true), 422, 'Tipo de roteiro invalido.');

        return $tipo;
    }


    public function clientes(): JsonResponse
    {
        $clientes = Cliente::whereHas('pedidos.consignacoes')
            ->select('id', 'nome')
            ->distinct()
            ->orderBy('nome')
            ->get();

        return response()->json($clientes);
    }

    public function vendedores(): JsonResponse
    {
        $vendedores = AcessoUsuario::whereHas('pedidos.consignacoes')
            ->select('id', 'nome')
            ->distinct()
            ->orderBy('nome')
            ->get();

        return response()->json($vendedores);
    }

}
