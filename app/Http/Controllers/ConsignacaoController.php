<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\ConsignacaoDetalhadaResource;
use App\Http\Resources\ConsignacaoResource;
use App\Models\Consignacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsignacaoController extends Controller
{

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Consignacao::with([
            'pedido.cliente:id,nome',
            'pedido.usuario:id,nome'
        ]);

        if (!AuthHelper::hasPermissao('consignacoes.visualizar.todos')) {
            $query->whereHas('pedido', function ($q) {
                $q->where('id_usuario', AuthHelper::getUsuarioId());
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('cliente')) {
            $cliente = $request->cliente;
            $query->whereHas('pedido.cliente', function ($q) use ($cliente) {
                $q->where('nome', 'like', "%$cliente%");
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

        $query->orderBy('prazo_resposta');

        return ConsignacaoResource::collection(
            $query->paginate($request->get('per_page', 10))
        );
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

}
