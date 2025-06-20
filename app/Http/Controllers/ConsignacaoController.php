<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\ConsignacaoDetalhadaResource;
use App\Http\Resources\ConsignacaoResource;
use App\Models\AcessoUsuario;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Pedido;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

class ConsignacaoController extends Controller
{

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Consignacao::with([
            'pedido.cliente:id,nome',
            'pedido.usuario:id,nome',
            'pedido.statusAtual',
            'produtoVariacao.produto',
        ]);

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
            'devolucoes.usuario'
        ])->findOrFail($id);

        return response()->json(new ConsignacaoDetalhadaResource($consignacao));
    }

    public function atualizarStatus($id, Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pendente,comprado,devolvido',
        ]);

        $consignacao = Consignacao::findOrFail($id);

        // Se já finalizada, não permitir alteração
        if (in_array($consignacao->status, ['aceita', 'devolvida'])) {
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
        ]);

        $consignacao = Consignacao::with('devolucoes')->findOrFail($id);

        $restante = $consignacao->quantidade - $consignacao->devolucoes->sum('quantidade');
        if ($request->quantidade > $restante) {
            return response()->json(['erro' => 'Quantidade devolvida excede o restante.'], 422);
        }

        $consignacao->devolucoes()->create([
            'quantidade' => $request->quantidade,
            'observacoes' => $request->observacoes,
            'usuario_id' => AuthHelper::getUsuarioId(),
        ]);

        $novaQuantidadeDevolvida = $consignacao->quantidadeDevolvida() + $request->quantidade;
        if ($novaQuantidadeDevolvida >= $consignacao->quantidade) {
            $consignacao->status = 'devolvido';
            $consignacao->data_resposta = now();
            $consignacao->save();
        }

        return response()->json(['mensagem' => 'Devolução registrada com sucesso.']);
    }

    public function gerarPdf(int $id): Response
    {
        $pedido = Pedido::with([
            'cliente',
            'usuario',
            'consignacoes.deposito',
            'consignacoes.produtoVariacao.produto.imagemPrincipal',
            'consignacoes.produtoVariacao.produto',
            'consignacoes.produtoVariacao.atributos',
        ])->findOrFail($id);

        $grupos = $pedido->consignacoes->groupBy(fn($item) => $item->deposito->nome ?? 'Sem depósito');

        logAuditoria('consignacao_pdf', 'Geração de PDF de roteiro de consignação', [
            'acao' => 'gerar_pdf',
            'pedido_id' => $id,
        ]);

        $pdf = Pdf::loadView('exports.roteiro-consignacao', [
            'pedido' => $pedido,
            'grupos' => $grupos,
        ])->setPaper('a4');

        return $pdf->download("roteiro_consignacao_$id.pdf");
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
