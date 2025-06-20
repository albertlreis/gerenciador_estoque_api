<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarrinhoItemController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ConsignacaoController;
use App\Http\Controllers\PedidoStatusHistoricoController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ProdutoImagemController;
use App\Http\Controllers\ProdutoOutletController;
use App\Http\Controllers\ProdutoVariacaoController;
use App\Http\Controllers\ProdutoAtributoController;
use App\Http\Controllers\DepositoController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\EstoqueMovimentacaoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ParceiroController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\PedidoItemController;
use App\Http\Controllers\CarrinhoController;
use App\Http\Controllers\ConfiguracaoController;

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    // Configurações do sistema
    Route::get('configuracoes', [ConfiguracaoController::class, 'listar']);
    Route::put('configuracoes/{chave}', [ConfiguracaoController::class, 'atualizar']);

    Route::get('/dashboard/resumo', [DashboardController::class, 'resumo']);

    // Produtos e Categorias
    Route::apiResource('categorias', CategoriaController::class);

    Route::get('produtos/outlet', [ProdutoOutletController::class, 'index']);
    Route::patch('produtos/{id}/remover-outlet', [ProdutoOutletController::class, 'removerOutlet']);


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

    // Rotas de estoque e movimentações
    Route::prefix('estoque')->group(function () {
        Route::get('atual', [EstoqueController::class, 'listarEstoqueAtual']);
        Route::get('resumo', [EstoqueController::class, 'resumoEstoque']);

        // Movimentações
        Route::get('movimentacoes', [EstoqueMovimentacaoController::class, 'index']);
        Route::post('produtos/{produto}/movimentacoes', [EstoqueMovimentacaoController::class, 'store']);
        Route::get('produtos/{produto}/movimentacoes/{movimentacao}', [EstoqueMovimentacaoController::class, 'show']);
        Route::put('produtos/{produto}/movimentacoes/{movimentacao}', [EstoqueMovimentacaoController::class, 'update']);
        Route::delete('produtos/{produto}/movimentacoes/{movimentacao}', [EstoqueMovimentacaoController::class, 'destroy']);
    });

    // Estoque por depósito
    Route::apiResource('depositos.estoque', EstoqueController::class)->shallow();

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
        Route::get('completo', [PedidoController::class, 'completo']);
    });

    Route::delete('pedidos/status/{statusHistorico}', [PedidoStatusHistoricoController::class, 'cancelarStatus']);
    Route::apiResource('pedidos', PedidoController::class);
    Route::apiResource('pedidos.itens', PedidoItemController::class)->parameters(['itens' => 'item']);


    Route::get('carrinhos', [CarrinhoController::class, 'index']);
    Route::get('carrinhos/{id}', [CarrinhoController::class, 'show']);
    Route::post('carrinhos', [CarrinhoController::class, 'store']);
    Route::put('carrinhos/{id}', [CarrinhoController::class, 'update']);
    Route::post('carrinhos/{id}/cancelar', [CarrinhoController::class, 'cancelar']);

    Route::post('carrinho-itens', [CarrinhoItemController::class, 'store']);
    Route::delete('carrinho-itens/{id}', [CarrinhoItemController::class, 'destroy']);
    Route::delete('carrinho-itens/limpar/{idCarrinho}', [CarrinhoItemController::class, 'clear']);

    Route::prefix('consignacoes')->group(function () {
        Route::get('/', [ConsignacaoController::class, 'index']);
        Route::patch('{id}', [ConsignacaoController::class, 'atualizarStatus']);
        Route::get('pedido/{pedido_id}', [ConsignacaoController::class, 'porPedido']);
        Route::get('vencendo', [ConsignacaoController::class, 'vencendo']);
        Route::get('clientes', [ConsignacaoController::class, 'clientes']);
        Route::get('vendedores', [ConsignacaoController::class, 'vendedores']);
        Route::post('{id}/devolucao', [ConsignacaoController::class, 'registrarDevolucao']);
        Route::get('{id}', [ConsignacaoController::class, 'show']);
    });

});
