<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\StoreCarrinhoRequest;
use App\Http\Requests\UpdateCarrinhoRequest;
use App\Http\Resources\CarrinhoResource;
use App\Models\Carrinho;
use Illuminate\Support\Facades\Auth;

/**
 * Controller responsável por gerenciar os carrinhos de compra dos usuários.
 */
class CarrinhoController extends Controller
{
    /**
     * Lista todos os carrinhos em rascunho do usuário logado.
     */
    public function index()
    {
        $query = Carrinho::where('status', 'rascunho');

        if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
            $query->where('id_usuario', Auth::id());
        }

        return CarrinhoResource::collection($query->with('cliente')->get());
    }

    /**
     * Retorna os dados de um carrinho específico do usuário logado.
     */
    public function show($id)
    {
        $carrinho = Carrinho::with([
            'cliente',
            'itens.variacao.produto.imagemPrincipal',
            'itens.variacao.produto',
            'itens.variacao.estoque',
            'itens.variacao.atributos'
        ])
            ->where('id_usuario', Auth::id())
            ->findOrFail($id);

        return new CarrinhoResource($carrinho);
    }

    /**
     * Cria um novo carrinho vinculado a um cliente.
     */
    public function store(StoreCarrinhoRequest $request)
    {
        $request->validate([
            'id_cliente' => 'required|exists:clientes,id'
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => Auth::id(),
            'id_cliente' => $request->id_cliente,
            'status' => 'rascunho'
        ]);

        return response()->json($carrinho, 201);
    }

    /**
     * Atualiza o cliente e/ou parceiro vinculados ao carrinho.
     */
    public function update(UpdateCarrinhoRequest $request, $id)
    {
        $request->validate([
            'id_cliente' => 'nullable|exists:clientes,id',
            'id_parceiro' => 'nullable|exists:parceiros,id'
        ]);

        $carrinho = Carrinho::where('id', $id)
            ->where('id_usuario', Auth::id())
            ->firstOrFail();

        $carrinho->update($request->only(['id_cliente', 'id_parceiro']));

        return response()->json($carrinho);
    }

    /**
     * Marca um carrinho como cancelado.
     */
    public function cancelar($id)
    {
        $carrinho = Carrinho::where('id', $id)
            ->where('id_usuario', Auth::id())
            ->firstOrFail();

        $carrinho->update(['status' => 'cancelado']);

        return response()->json(['message' => 'Carrinho cancelado com sucesso.']);
    }
}
