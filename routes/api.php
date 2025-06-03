<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{CarrinhoItemController,
    CategoriaController,
    ConsignacaoController,
    PedidoStatusHistoricoController,
    ProdutoController,
    ProdutoImagemController,
    ProdutoOutletController,
    ProdutoVariacaoController,
    ProdutoAtributoController,
    DepositoController,
    EstoqueController,
    EstoqueMovimentacaoController,
    ClienteController,
    ParceiroController,
    PedidoController,
    PedidoItemController,
    CarrinhoController,
    ConfiguracaoController};

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Configurações do sistema
    Route::get('/configuracoes', [ConfiguracaoController::class, 'listar']);
    Route::put('/configuracoes/{chave}', [ConfiguracaoController::class, 'atualizar']);

    // Produtos e Categorias
    Route::apiResource('categorias', CategoriaController::class);

    Route::get('/produtos/outlet', [ProdutoOutletController::class, 'index']);
    Route::patch('/produtos/{id}/remover-outlet', [ProdutoOutletController::class, 'removerOutlet']);


    Route::get('atributos', [ProdutoAtributoController::class, 'index']);

    // Variações
    Route::get('variacoes', [ProdutoVariacaoController::class, 'buscar']);
    Route::prefix('produtos')->group(function () {
        Route::get('estoque-baixo', [ProdutoController::class, 'estoqueBaixo']);
        Route::post('importar-xml', [ProdutoController::class, 'importarXML']);
        Route::post('importar-xml/confirmar', [ProdutoController::class, 'confirmarImportacao']);
        Route::apiResource('{produto}/imagens', ProdutoImagemController::class)->parameters(['imagens' => 'imagem']);
        Route::apiResource('{produto}/variacoes', ProdutoVariacaoController::class)->parameters(['variacoes' => 'variacao']);
    });

    Route::apiResource('produtos', ProdutoController::class);

    // Depósitos e Estoque
    Route::apiResource('depositos', DepositoController::class);
    Route::apiResource('depositos.estoque', EstoqueController::class)->shallow();
    Route::apiResource('depositos.movimentacoes', EstoqueMovimentacaoController::class)->shallow();

    // Clientes e Parceiros
    Route::apiResource('clientes', ClienteController::class);
    Route::get('clientes/verifica-documento/{documento}/{id?}', [ClienteController::class, 'verificaDocumento']);
    Route::apiResource('parceiros', ParceiroController::class);

    Route::get('pedidos/exportar', [PedidoController::class, 'exportar']);
    Route::get('pedidos/estatisticas', [PedidoController::class, 'estatisticas']);
    Route::post('pedidos/importar-pdf', [PedidoController::class, 'importarPDF']);
    Route::post('pedidos/importar-pdf/confirmar', [PedidoController::class, 'confirmarImportacaoPDF']);

    Route::prefix('pedidos/{pedido}')->group(function () {
        Route::patch('status', [PedidoController::class, 'updateStatus']);
        Route::get('historico-status', [PedidoStatusHistoricoController::class, 'historico']);
        Route::get('previsoes', [PedidoStatusHistoricoController::class, 'previsoes']);
    });

    Route::delete('pedidos/status/{statusHistorico}', [PedidoStatusHistoricoController::class, 'cancelarStatus']);
    Route::apiResource('pedidos', PedidoController::class);
    Route::apiResource('pedidos.itens', PedidoItemController::class)->parameters(['itens' => 'item']);


    Route::get('/carrinhos', [CarrinhoController::class, 'index']);
    Route::get('/carrinhos/{id}', [CarrinhoController::class, 'show']);
    Route::post('/carrinhos', [CarrinhoController::class, 'store']);
    Route::put('/carrinhos/{id}', [CarrinhoController::class, 'update']);
    Route::post('/carrinhos/{id}/cancelar', [CarrinhoController::class, 'cancelar']);

    Route::post('/carrinho-itens', [CarrinhoItemController::class, 'store']);
    Route::delete('/carrinho-itens/{id}', [CarrinhoItemController::class, 'destroy']);
    Route::delete('/carrinho-itens/limpar/{idCarrinho}', [CarrinhoItemController::class, 'clear']);

    Route::prefix('consignacoes')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [ConsignacaoController::class, 'index']);
        Route::patch('/{id}', [ConsignacaoController::class, 'atualizarStatus']);
        Route::get('/vencendo', [ConsignacaoController::class, 'vencendo']);
        Route::get('{id}', [ConsignacaoController::class, 'show']);
    });

});
