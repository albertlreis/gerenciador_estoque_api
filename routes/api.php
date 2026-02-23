<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\{AreaEstoqueController,
    AuditoriaController,
    CarrinhoController,
    CarrinhoItemController,
    CategoriaController,
    CategoriaFinanceiraController,
    CentroCustoController,
    ClienteController,
    ConfiguracaoController,
    ConsignacaoController,
    ConsignacaoRelatorioController,
    ContaFinanceiraController,
    ContaPagarController,
    ContaReceberController,
    ContaReceberExportController,
    ContaReceberRelatorioController,
    DashboardController,
    DespesaRecorrenteController,
    DevolucaoController,
    DepositoController,
    EstoqueController,
    EstoqueMovimentacaoController,
    EstoqueRelatorioController,
    EstoqueTransferenciaController,
    FeriadoController,
    FinanceiroDashboardController,
    FornecedorController,
    ImportEstoqueController,
    LancamentoFinanceiroController,
    LocalizacaoDimensaoController,
    LocalizacaoEstoqueController,
    OutletCatalogoController,
    ParceiroController,
    PedidoController,
    PedidoEstoqueController,
    PedidoFabricaController,
    PedidoItemController,
    PedidosRelatorioController,
    PedidoStatusHistoricoController,
    ProdutoController,
    ProdutoImagemController,
    ProdutoVariacaoController,
    ProdutoVariacaoImagemController,
    ProdutoVariacaoOutletController,
    ProdutoAtributoController,
    CommsProxyController,
    TransferenciaFinanceiraController};

use App\Http\Controllers\Assistencia\{
    AssistenciaArquivoController,
    AssistenciaChamadoController,
    AssistenciaDefeitosController,
    AssistenciaItemController,
    AssistenciasController,
    PedidoLookupController
};

use App\Http\Controllers\AssistenciaRelatorioController;

Route::middleware('auth:sanctum')
    ->prefix('v1')
    ->group(function () {

        /* ============================================================
         * SISTEMA / DASHBOARD
         * ============================================================ */
        Route::get('configuracoes', [ConfiguracaoController::class, 'listar']);
        Route::put('configuracoes/{chave}', [ConfiguracaoController::class, 'atualizar']);

        Route::get('dashboard/resumo', [DashboardController::class, 'resumo']);

        /* ============================================================
         * AUDITORIA
         * ============================================================ */
        Route::prefix('auditoria')->group(function () {
            Route::get('eventos', [AuditoriaController::class, 'index']);
            Route::get('eventos/{id}', [AuditoriaController::class, 'show'])->whereNumber('id');
            Route::get('entidade', [AuditoriaController::class, 'entidade']);
        });

        /* ============================================================
         * CATÁLOGO (CATEGORIAS / ATRIBUTOS / PRODUTOS / VARIAÇÕES / OUTLET)
         * ============================================================ */
        Route::apiResource('categorias', CategoriaController::class)->except(['create', 'edit']);

        // Atributos (mantém sem virar resource)
        Route::get('atributos', [ProdutoAtributoController::class, 'index']);
        Route::get('atributos/sugestoes', [ProdutoAtributoController::class, 'nomes']);
        Route::get('atributos/{nome}/valores', [ProdutoAtributoController::class, 'valores']);

        // Variações (busca/listagem global)
        Route::get('variacoes', [ProdutoVariacaoController::class, 'buscar']);

        Route::prefix('variacoes/{variacao}')->whereNumber('variacao')->group(function () {
            Route::get('imagem', [ProdutoVariacaoImagemController::class, 'show']);
            Route::post('imagem', [ProdutoVariacaoImagemController::class, 'store']);
            Route::put('imagem', [ProdutoVariacaoImagemController::class, 'update']);
            Route::delete('imagem', [ProdutoVariacaoImagemController::class, 'destroy']);
        });

        // Catálogo Outlet
        Route::prefix('outlet')->group(function () {
            Route::get('motivos', [OutletCatalogoController::class, 'motivos']);
            Route::get('formas-pagamento', [OutletCatalogoController::class, 'formas']);
        });

        // Outlet por variação (plural + padrão)
        Route::prefix('variacoes/{variacao}/outlets')->whereNumber('variacao')->group(function () {
            Route::get('/', [ProdutoVariacaoOutletController::class, 'index']);
            Route::post('/', [ProdutoVariacaoOutletController::class, 'store']);
            Route::put('{outlet}', [ProdutoVariacaoOutletController::class, 'update'])->whereNumber('outlet');
            Route::delete('{outlet}', [ProdutoVariacaoOutletController::class, 'destroy'])->whereNumber('outlet');
        });

        // Produtos
        Route::prefix('produtos')->group(function () {
            Route::get('estoque-baixo', [ProdutoController::class, 'estoqueBaixo']);
            Route::get('sugestoes-outlet', [ProdutoController::class, 'sugestoesOutlet']);

            // Importações XML (padronizado)
            Route::post('importacoes/xml', [ProdutoController::class, 'importarXML']);
            Route::post('importacoes/xml/confirmar', [ProdutoController::class, 'confirmarImportacao']);

            // Exportação de catálogo outlet (selecionados)
            Route::post('outlet/export', [ProdutoController::class, 'exportarOutlet']);

            // Imagens
            Route::post('{produto}/imagens/{imagem}/definir-principal', [ProdutoImagemController::class, 'definirPrincipal'])
                ->whereNumber(['produto', 'imagem']);

            Route::apiResource('{produto}/imagens', ProdutoImagemController::class)
                ->parameters(['imagens' => 'imagem'])
                ->whereNumber(['produto', 'imagem'])
                ->except(['create', 'edit']);

            // Variações (nested resource)
            Route::apiResource('{produto}/variacoes', ProdutoVariacaoController::class)
                ->parameters(['variacoes' => 'variacao'])
                ->whereNumber(['produto', 'variacao'])
                ->except(['create', 'edit']);

            // Bulk update de variações (não conflita com resource)
            Route::patch('{produto}/variacoes/bulk', [ProdutoVariacaoController::class, 'update'])
                ->whereNumber('produto');
        });

        Route::apiResource('produtos', ProdutoController::class)
            ->whereNumber('produto')
            ->except(['create', 'edit']);

        /* ============================================================
         * ESTOQUE
         * ============================================================ */
        Route::prefix('estoque')->group(function () {
            Route::get('atual', [EstoqueController::class, 'listarEstoqueAtual']);
            Route::get('resumo', [EstoqueController::class, 'resumoEstoque']);

            // padroniza rota "por variação"
            Route::get('variacoes/{variacao}', [EstoqueController::class, 'porVariacao'])
                ->whereNumber('variacao');

            // Movimentações (resource + ação em lote)
            Route::apiResource('movimentacoes', EstoqueMovimentacaoController::class)
                ->parameters(['movimentacoes' => 'movimentacao'])
                ->whereNumber('movimentacao')
                ->except(['create', 'edit']);

            Route::post('movimentacoes/{movimentacao}/estornar', [EstoqueMovimentacaoController::class, 'estornar'])
                ->whereNumber('movimentacao');

            Route::post('movimentacoes/lote', [EstoqueMovimentacaoController::class, 'lote']);

            // Áreas (sem show)
            Route::apiResource('areas', AreaEstoqueController::class)
                ->parameters(['areas' => 'area'])
                ->whereNumber('area')
                ->only(['index', 'store', 'update', 'destroy'])
                ->except(['create', 'edit']);

            // Dimensões (sem show)
            Route::apiResource('dimensoes', LocalizacaoDimensaoController::class)
                ->parameters(['dimensoes' => 'dimensao'])
                ->whereNumber('dimensao')
                ->only(['index', 'store', 'update', 'destroy'])
                ->except(['create', 'edit']);

            Route::get('transferencias', [EstoqueTransferenciaController::class, 'index']);
            Route::get('transferencias/{transferencia}', [EstoqueTransferenciaController::class, 'show'])->whereNumber('transferencia');

            // PDF do roteiro de separação da transferência
            Route::get('transferencias/{transferencia}/pdf', [EstoqueTransferenciaController::class, 'pdf'])
                ->whereNumber('transferencia')
                ->name('estoque.transferencias.pdf');
        });

        // Depósitos
        Route::apiResource('depositos', DepositoController::class)
            ->parameters(['depositos' => 'deposito'])
            ->whereNumber('deposito')
            ->except(['create', 'edit']);

        // Estoque por depósito (NÃO shallow, para ficar previsível no front)
        Route::apiResource('depositos.estoques', EstoqueController::class)
            ->parameters(['depositos' => 'deposito', 'estoques' => 'estoque'])
            ->whereNumber(['deposito', 'estoque'])
            ->except(['create', 'edit']);

        // Localizações de estoque
        Route::apiResource('localizacoes-estoque', LocalizacaoEstoqueController::class)
            ->parameters(['localizacoes-estoque' => 'localizacao'])
            ->whereNumber('localizacao')
            ->except(['create', 'edit']);

        // Importações de estoque (pt-BR)
        Route::prefix('importacoes/estoque')->group(function () {
            Route::post('/', [ImportEstoqueController::class, 'store']);
            Route::post('{importacao}/processar', [ImportEstoqueController::class, 'processar'])->whereNumber('importacao');
            Route::get('{importacao}', [ImportEstoqueController::class, 'show'])->whereNumber('importacao');
        });

        /* ============================================================
         * PESSOAS (CLIENTES / FORNECEDORES / PARCEIROS)
         * ============================================================ */
        Route::apiResource('clientes', ClienteController::class)
            ->parameters(['clientes' => 'cliente'])
            ->whereNumber('cliente')
            ->except(['create', 'edit']);

        // documento via query params (evita URL com dados sensíveis e é mais flexível)
        Route::get('clientes/verificar-documento', [ClienteController::class, 'verificaDocumento']);
        // espera: ?documento=...&ignorar_id=...

        Route::apiResource('fornecedores', FornecedorController::class)
            ->parameters(['fornecedores' => 'fornecedor'])
            ->whereNumber('fornecedor')
            ->except(['create', 'edit']);

        Route::patch('fornecedores/{fornecedor}/restaurar', [FornecedorController::class, 'restore'])
            ->whereNumber('fornecedor');

        Route::get('fornecedores/{fornecedor}/produtos', [FornecedorController::class, 'produtos'])
            ->whereNumber('fornecedor');

        Route::apiResource('parceiros', ParceiroController::class)
            ->parameters(['parceiros' => 'parceiro'])
            ->whereNumber('parceiro')
            ->except(['create', 'edit']);

        Route::patch('parceiros/{parceiro}/restaurar', [ParceiroController::class, 'restore'])
            ->whereNumber('parceiro');

        /* ============================================================
         * PEDIDOS / ITENS / STATUS / ESTOQUE DO PEDIDO
         * ============================================================ */
        Route::prefix('pedidos')->group(function () {
            Route::get('export', [PedidoController::class, 'exportar']);
            Route::get('stats', [PedidoController::class, 'estatisticas']);

            Route::post('import', [PedidoController::class, 'importar']);
            Route::post('import/pdf/confirm', [PedidoController::class, 'confirmarImportacaoPDF']);
        });

        Route::prefix('pedidos/{pedido}')->whereNumber('pedido')->group(function () {
            Route::get('detalhado', [PedidoController::class, 'completo']);
            Route::get('pdf/roteiro', [PedidoController::class, 'roteiroPdf']);
            Route::post('xml', [PedidoController::class, 'uploadXml']);
            Route::get('xml', [PedidoController::class, 'downloadXml']);

            // status (padronizado)
            Route::patch('status', [PedidoStatusHistoricoController::class, 'atualizarStatus']);
            Route::get('status/historico', [PedidoStatusHistoricoController::class, 'historico']);
            Route::get('status/previsoes', [PedidoStatusHistoricoController::class, 'previsoes']);
            Route::get('status/fluxo', [PedidoStatusHistoricoController::class, 'fluxoStatus']);

            // ações de estoque do pedido
            Route::post('estoque/reservar', [PedidoEstoqueController::class, 'reservar']);
            Route::post('estoque/expedir', [PedidoEstoqueController::class, 'expedir']);
            Route::post('estoque/cancelar-reservas', [PedidoEstoqueController::class, 'cancelarReservas']);
        });

        // remove status histórico vinculado ao pedido (mais consistente)
        Route::delete('pedidos/{pedido}/status-historicos/{statusHistorico}', [PedidoStatusHistoricoController::class, 'cancelarStatus'])
            ->whereNumber(['pedido', 'statusHistorico']);

        Route::apiResource('pedidos', PedidoController::class)
            ->parameters(['pedidos' => 'pedido'])
            ->whereNumber('pedido')
            ->except(['create', 'edit']);

        Route::apiResource('pedidos.itens', PedidoItemController::class)
            ->parameters(['pedidos' => 'pedido', 'itens' => 'item'])
            ->whereNumber(['pedido', 'item'])
            ->except(['create', 'edit']);

        // itens global (antes era /pedido-itens)
        Route::get('pedidos/itens', [PedidoItemController::class, 'indexGlobal']);
        Route::patch('pedidos/itens/{item}/liberar-entrega', [PedidoItemController::class, 'liberarEntrega'])
            ->whereNumber('item');

        /* ============================================================
         * CARRINHOS (resource + itens nested)
         * ============================================================ */
        Route::apiResource('carrinhos', CarrinhoController::class)
            ->parameters(['carrinhos' => 'carrinho'])
            ->whereNumber('carrinho')
            ->only(['index', 'show', 'store', 'update'])
            ->except(['create', 'edit']);

        Route::patch('carrinhos/{carrinho}/cancelar', [CarrinhoController::class, 'cancelar'])
            ->whereNumber('carrinho');

        Route::prefix('carrinhos/{carrinho}/itens')->whereNumber('carrinho')->group(function () {
            Route::post('/', [CarrinhoItemController::class, 'store']);
            Route::delete('{item}', [CarrinhoItemController::class, 'destroy'])->whereNumber('item');
            Route::delete('/', [CarrinhoItemController::class, 'clear']);
            Route::patch('atualizar-deposito', [CarrinhoItemController::class, 'atualizarDeposito']);
        });

        /* ============================================================
         * CONSIGNAÇÕES
         * ============================================================ */
        Route::prefix('consignacoes')->group(function () {
            Route::get('/', [ConsignacaoController::class, 'index']);

            Route::get('pedidos/{pedido}', [ConsignacaoController::class, 'porPedido'])->whereNumber('pedido');

            Route::get('vencendo', [ConsignacaoController::class, 'vencendo']);
            Route::get('clientes', [ConsignacaoController::class, 'clientes']);
            Route::get('vendedores', [ConsignacaoController::class, 'vendedores']);

            Route::get('{consignacao}', [ConsignacaoController::class, 'show'])->whereNumber('consignacao');

            Route::patch('{consignacao}/status', [ConsignacaoController::class, 'atualizarStatus'])
                ->whereNumber('consignacao');

            Route::post('{consignacao}/devolucoes', [ConsignacaoController::class, 'registrarDevolucao'])
                ->whereNumber('consignacao');

            Route::get('{pedido}/pdf', [ConsignacaoController::class, 'gerarPdf'])->whereNumber('pedido');
        });

        /* ============================================================
         * PEDIDOS FÁBRICA
         * ============================================================ */
        Route::apiResource('pedidos-fabrica', PedidoFabricaController::class)
            ->parameters(['pedidos-fabrica' => 'pedidoFabrica'])
            ->whereNumber('pedidoFabrica')
            ->except(['create', 'edit']);

        Route::patch('pedidos-fabrica/{pedidoFabrica}/status', [PedidoFabricaController::class, 'updateStatus'])
            ->whereNumber('pedidoFabrica');

        Route::patch('pedidos-fabrica/itens/{item}/entrega', [PedidoFabricaController::class, 'registrarEntrega'])
            ->whereNumber('item');

        /* ============================================================
         * DEVOLUÇÕES (pt-BR + PATCH para estado)
         * ============================================================ */
        Route::post('devolucoes', [DevolucaoController::class, 'store']);
        Route::patch('devolucoes/{devolucao}/aprovar', [DevolucaoController::class, 'approve'])->whereNumber('devolucao');
        Route::patch('devolucoes/{devolucao}/reprovar', [DevolucaoController::class, 'reject'])->whereNumber('devolucao');

        /* ============================================================
         * RELATÓRIOS
         * ============================================================ */
        Route::prefix('relatorios')->group(function () {
            Route::get('estoque/atual', [EstoqueRelatorioController::class, 'estoqueAtual']);
            Route::get('pedidos', [PedidosRelatorioController::class, 'pedidosPorPeriodo']);
            Route::get('consignacoes/ativas', [ConsignacaoRelatorioController::class, 'consignacoesAtivas']);
            Route::get('assistencias', [AssistenciaRelatorioController::class, 'assistencias']);

            Route::get('devedores', [ContaReceberRelatorioController::class, 'devedores']);
            Route::get('devedores/export/excel', [ContaReceberRelatorioController::class, 'exportarExcel']);
            Route::get('devedores/export/pdf', [ContaReceberRelatorioController::class, 'exportarPdf']);
        });

        /* ============================================================
         * FERIADOS
         * ============================================================ */
        Route::prefix('feriados')->group(function () {
            Route::get('/', [FeriadoController::class, 'index']);
            Route::post('sincronizar', [FeriadoController::class, 'sync']);
        });

        /* ============================================================
         * ASSISTÊNCIAS
         * ============================================================ */
        Route::prefix('assistencias')->group(function () {
            // autorizadas
            Route::apiResource('autorizadas', AssistenciasController::class)->except(['create', 'edit']);

            // defeitos (sem show)
            Route::apiResource('defeitos', AssistenciaDefeitosController::class)
                ->only(['index', 'store', 'update', 'destroy'])
                ->except(['create', 'edit']);

            // chamados
            Route::apiResource('chamados', AssistenciaChamadoController::class)->except(['create', 'edit']);
            Route::patch('chamados/{chamado}/cancelar', [AssistenciaChamadoController::class, 'cancelar'])->whereNumber('chamado');

            // itens do chamado
            Route::post('chamados/{chamado}/itens', [AssistenciaItemController::class, 'store'])->whereNumber('chamado');

            // ações por item (padroniza prefixo)
            Route::prefix('itens/{item}')->whereNumber('item')->group(function () {
                Route::post('iniciar-reparo', [AssistenciaItemController::class, 'iniciarReparo']);
                Route::post('enviar', [AssistenciaItemController::class, 'enviar']);
                Route::post('orcamento', [AssistenciaItemController::class, 'orcamento']);
                Route::post('aprovar-orcamento', [AssistenciaItemController::class, 'aprovar']);
                Route::post('reprovar-orcamento', [AssistenciaItemController::class, 'reprovar']);
                Route::post('retorno', [AssistenciaItemController::class, 'retorno']);
                Route::post('concluir-reparo', [AssistenciaItemController::class, 'concluirReparo']);

                Route::post('aguardar-resposta', [AssistenciaItemController::class, 'aguardarResposta']);
                Route::post('aguardar-peca', [AssistenciaItemController::class, 'aguardarPeca']);
                Route::post('saida-fabrica', [AssistenciaItemController::class, 'saidaFabrica']);
                Route::post('entregar', [AssistenciaItemController::class, 'entregar']);
            });

            // lookup pedidos
            Route::get('pedidos/busca', [PedidoLookupController::class, 'buscar']);
            Route::get('pedidos/{pedido}/produtos', [PedidoLookupController::class, 'produtos'])->whereNumber('pedido');

            // arquivos
            Route::get('chamados/{chamado}/arquivos', [AssistenciaArquivoController::class, 'listByChamado'])->whereNumber('chamado');
            Route::post('chamados/{chamado}/arquivos', [AssistenciaArquivoController::class, 'uploadToChamado'])->whereNumber('chamado');

            Route::get('itens/{item}/arquivos', [AssistenciaArquivoController::class, 'listByItem'])->whereNumber('item');
            Route::post('itens/{item}/arquivos', [AssistenciaArquivoController::class, 'uploadToItem'])->whereNumber('item');

            Route::get('arquivos/{arquivo}', [AssistenciaArquivoController::class, 'show'])->whereNumber('arquivo');
            Route::delete('arquivos/{arquivo}', [AssistenciaArquivoController::class, 'destroy'])->whereNumber('arquivo');
        });

        /* ============================================================
         * FINANCEIRO
         * ============================================================ */
        Route::prefix('financeiro')->group(function () {
            Route::get('dashboard', [FinanceiroDashboardController::class, 'show']);

            Route::apiResource('categorias-financeiras', CategoriaFinanceiraController::class)
                ->parameters(['categorias-financeiras' => 'categoria_financeira'])
                ->except(['create', 'edit']);

            Route::apiResource('contas-financeiras', ContaFinanceiraController::class)
                ->parameters(['contas-financeiras' => 'conta_financeira'])
                ->except(['create', 'edit']);

            Route::apiResource('centros-custo', CentroCustoController::class)
                ->parameters(['centros-custo' => 'centro_custo'])
                ->except(['create', 'edit']);

            Route::apiResource('transferencias', TransferenciaFinanceiraController::class)
                ->parameters(['transferencias' => 'transferencia'])
                ->except(['create', 'edit']);

            Route::get('lancamentos/totais', [LancamentoFinanceiroController::class, 'totais']);
            Route::apiResource('lancamentos', LancamentoFinanceiroController::class)->except(['create', 'edit']);

            Route::prefix('contas-pagar')->group(function () {
                Route::get('export/excel', [ContaPagarController::class, 'exportExcel']);
                Route::get('export/pdf', [ContaPagarController::class, 'exportPdf']);
                Route::get('kpis', [ContaPagarController::class, 'kpis']);

                Route::post('{conta_pagar}/pagar', [ContaPagarController::class, 'pagar'])->whereNumber('conta_pagar');
                Route::delete('{conta_pagar}/pagamentos/{pagamento}', [ContaPagarController::class, 'estornar'])
                    ->whereNumber(['conta_pagar', 'pagamento']);
            });

            Route::apiResource('contas-pagar', ContaPagarController::class)
                ->parameters(['contas-pagar' => 'conta_pagar'])
                ->whereNumber('conta_pagar')
                ->except(['create', 'edit']);

            Route::prefix('contas-receber')->group(function () {
                Route::get('export/excel', [ContaReceberExportController::class, 'exportarExcel']);
                Route::get('export/pdf', [ContaReceberExportController::class, 'exportarPdf']);
                Route::get('kpis', [ContaReceberExportController::class, 'kpis']);

                Route::post('{conta}/pagar', [ContaReceberController::class, 'pagar'])->whereNumber('conta');
                Route::delete('{conta}/pagamentos/{pagamento}', [ContaReceberController::class, 'estornarPagamento'])
                    ->whereNumber(['conta', 'pagamento']);
            });

            Route::apiResource('contas-receber', ContaReceberController::class)
                ->parameters(['contas-receber' => 'conta'])
                ->whereNumber('conta')
                ->except(['create', 'edit']);

            Route::get('despesas-recorrentes', [DespesaRecorrenteController::class, 'index']);
            Route::get('despesas-recorrentes/{id}', [DespesaRecorrenteController::class, 'show'])->whereNumber('id');
            Route::post('despesas-recorrentes', [DespesaRecorrenteController::class, 'store']);
            Route::put('despesas-recorrentes/{id}', [DespesaRecorrenteController::class, 'update'])->whereNumber('id');

            Route::patch('despesas-recorrentes/{id}/pausar', [DespesaRecorrenteController::class, 'pause'])->whereNumber('id');
            Route::patch('despesas-recorrentes/{id}/ativar', [DespesaRecorrenteController::class, 'activate'])->whereNumber('id');
            Route::patch('despesas-recorrentes/{id}/cancelar', [DespesaRecorrenteController::class, 'cancel'])->whereNumber('id');

            Route::post('despesas-recorrentes/{id}/executar', [DespesaRecorrenteController::class, 'executar'])->whereNumber('id');
        });

        /* ============================================================
         * COMUNICAÇÃO
         * ============================================================ */
        Route::prefix('comunicacao')->group(function () {
            Route::get('templates', [CommsProxyController::class, 'templatesIndex']);
            Route::get('templates/{id}', [CommsProxyController::class, 'templatesShow']);
            Route::post('templates', [CommsProxyController::class, 'templatesStore']);
            Route::put('templates/{id}', [CommsProxyController::class, 'templatesUpdate']);
            Route::post('templates/{id}/preview', [CommsProxyController::class, 'templatesPreview']);

            Route::get('requests', [CommsProxyController::class, 'requestsIndex']);
            Route::get('requests/{id}', [CommsProxyController::class, 'requestsShow']);
            Route::post('requests/{id}/cancelar', [CommsProxyController::class, 'requestsCancel']);

            Route::get('messages', [CommsProxyController::class, 'messagesIndex']);
            Route::get('messages/{id}', [CommsProxyController::class, 'messagesShow']);
            Route::post('messages/{id}/reprocessar', [CommsProxyController::class, 'messagesRetry']);
        });
    });
