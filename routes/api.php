<?php

use App\Http\Controllers\CarrinhoController;
use App\Http\Controllers\ProdutoAtributoController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ProdutoImagemController;
use App\Http\Controllers\ProdutoVariacaoController;
use App\Http\Controllers\DepositoController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\EstoqueMovimentacaoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ParceiroController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\PedidoItemController;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // ================================
    // Categorias e Produtos
    // ================================
    // Rotas para categorias
    Route::apiResource('categorias', CategoriaController::class);
    // Produtos pertencentes a uma categoria (rotas aninhadas)
    Route::apiResource('produtos', ProdutoController::class);
    Route::get('/atributos', [ProdutoAtributoController::class, 'index']);

    // ================================
    // Produtos: Imagens e Variações
    // ================================
    // Imagens associadas a um produto
    Route::apiResource('produtos.imagens', ProdutoImagemController::class, [
        'parameters' => ['imagens' => 'imagem']
    ]);
    // Variações associadas a um produto
    Route::apiResource('produtos.variacoes', ProdutoVariacaoController::class);

    // ================================
    // Estoque e Depósitos
    // ================================
    // Rotas para depósitos
    Route::apiResource('depositos', DepositoController::class);
    // Estoque dos depósitos (cada depósito possui seus registros de estoque)
    Route::apiResource('depositos.estoque', EstoqueController::class);

    // ================================
    // Clientes e Parceiros
    // ================================
    Route::apiResource('clientes', ClienteController::class);
    Route::get('/clientes/verifica-documento/{documento}/{id?}', [ClienteController::class, 'verificaDocumento']);
    Route::apiResource('parceiros', ParceiroController::class);

    // ================================
    // Pedidos e Itens de Pedido
    // ================================
    // Rotas para pedidos
    Route::prefix('carrinho')->group(function () {
        Route::get('/', [CarrinhoController::class, 'index']);
        Route::post('/', [CarrinhoController::class, 'store']);
        Route::delete('/', [CarrinhoController::class, 'clear']);
        Route::delete('/{itemId}', [CarrinhoController::class, 'destroy']);
    });

    Route::get('pedidos/exportar', [PedidoController::class, 'exportar']);
    Route::patch('pedidos/{pedido}/status', [PedidoController::class, 'updateStatus']);
    Route::apiResource('pedidos', PedidoController::class);

    // Itens pertencentes a um pedido (rotas aninhadas)
    Route::apiResource('pedidos.itens', PedidoItemController::class, [
        'parameters' => ['itens' => 'item']
    ]);
});
