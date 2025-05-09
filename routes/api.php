<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    CategoriaController,
    ProdutoController,
    ProdutoImagemController,
    ProdutoVariacaoController,
    ProdutoAtributoController,
    DepositoController,
    EstoqueController,
    EstoqueMovimentacaoController,
    ClienteController,
    ParceiroController,
    PedidoController,
    PedidoItemController,
    CarrinhoController
};

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // Produtos e Categorias
    Route::apiResource('categorias', CategoriaController::class);
    Route::apiResource('produtos', ProdutoController::class);
    Route::get('atributos', [ProdutoAtributoController::class, 'index']);

    // Rota separada para busca de variações (evita conflito com produtos/{id})
    Route::get('variacoes', [ProdutoVariacaoController::class, 'buscar']);

    Route::prefix('produtos')->group(function () {
        Route::post('importar-xml', [ProdutoController::class, 'importarXML']);
        Route::post('importar-xml/confirmar', [ProdutoController::class, 'confirmarImportacao']);

        Route::apiResource('{produto}/imagens', ProdutoImagemController::class)->parameters(['imagens' => 'imagem']);
        Route::apiResource('{produto}/variacoes', ProdutoVariacaoController::class)->parameters(['variacoes' => 'variacao']);
    });

    // Depósitos e Estoque
    Route::apiResource('depositos', DepositoController::class);
    Route::apiResource('depositos.estoque', EstoqueController::class)->shallow();
    Route::apiResource('depositos.movimentacoes', EstoqueMovimentacaoController::class)->shallow();

    // Clientes e Parceiros
    Route::apiResource('clientes', ClienteController::class);
    Route::get('clientes/verifica-documento/{documento}/{id?}', [ClienteController::class, 'verificaDocumento']);
    Route::apiResource('parceiros', ParceiroController::class);

    // Pedidos e Carrinho
    Route::get('pedidos/exportar', [PedidoController::class, 'exportar']);
    Route::get('pedidos/estatisticas', [PedidoController::class, 'estatisticas']);
    Route::patch('pedidos/{pedido}/status', [PedidoController::class, 'updateStatus']);
    Route::apiResource('pedidos', PedidoController::class);
    Route::apiResource('pedidos.itens', PedidoItemController::class)->parameters(['itens' => 'item']);

    Route::prefix('carrinho')->group(function () {
        Route::get('/', [CarrinhoController::class, 'index']);
        Route::post('/', [CarrinhoController::class, 'store']);
        Route::delete('/', [CarrinhoController::class, 'clear']);
        Route::delete('/{itemId}', [CarrinhoController::class, 'destroy']);
    });
});
