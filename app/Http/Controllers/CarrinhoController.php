<?php

namespace App\Http\Controllers;

use App\Models\Carrinho;
use Illuminate\Http\Request;
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
        $carrinhos = Carrinho::with('cliente', 'itens.variacao')
            ->where('id_usuario', Auth::id())
            ->where('status', 'rascunho')
            ->get();

        return response()->json($carrinhos);
    }

    /**
     * Retorna os dados de um carrinho específico do usuário logado.
     */
    public function show($id)
    {
        $carrinho = Carrinho::with([
            'cliente',
            'itens.variacao.produto.imagemPrincipal', // traz a imagem principal
            'itens.variacao.produto',                 // nome do produto
            'itens.variacao.estoque',                 // quantidade disponível
            'itens.variacao.atributos'                // atributos da variação
        ])
            ->where('id_usuario', Auth::id())
            ->findOrFail($id);

        return response()->json($carrinho);
    }

    /**
     * Cria um novo carrinho vinculado a um cliente.
     */
    public function store(Request $request)
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
    public function update(Request $request, $id)
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
