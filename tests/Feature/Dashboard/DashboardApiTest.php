<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_admin_retorna_resumo_operacional_e_ignora_compare(): void
    {
        $admin = $this->autenticar(['dashboard.admin', 'pedidos.visualizar.todos']);

        $clienteId = $this->criarCliente('Cliente Admin');
        $vendedorId = $this->criarUsuario('Vendedor Admin')->id;

        $pedidoAtual = $this->criarPedido($clienteId, $vendedorId, now()->subDay(), 300.00);
        $this->criarStatusPedido($pedidoAtual->id, PedidoStatus::PEDIDO_CRIADO->value, $admin->id);
        DB::table('pedido_status_previsoes')->insert([
            'pedido_id' => $pedidoAtual->id,
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
            'data_prevista' => now()->subDay()->toDateString(),
            'usuario_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pedidoAnterior = $this->criarPedido($clienteId, $vendedorId, now()->subMonth()->startOfMonth()->addDay(), 200.00);
        $this->criarStatusPedido($pedidoAnterior->id, PedidoStatus::FINALIZADO->value, $admin->id);

        $depositoId = DB::table('depositos')->insertGetId([
            'nome' => 'Deposito Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoriaAdornosId = DB::table('categorias')->insertGetId([
            'nome' => '  ADORNOS  ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subcategoriaAdornosId = DB::table('categorias')->insertGetId([
            'nome' => 'Objetos Decorativos',
            'categoria_pai_id' => $categoriaAdornosId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => 'Produto Antigo Admin',
            'id_categoria' => $categoriaId,
            'estoque_minimo' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $produtoAdornoId = DB::table('produtos')->insertGetId([
            'nome' => 'Adorno Antigo Admin',
            'id_categoria' => $categoriaAdornosId,
            'estoque_minimo' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $produtoSubcategoriaAdornoId = DB::table('produtos')->insertGetId([
            'nome' => 'Adorno Filho Antigo Admin',
            'id_categoria' => $subcategoriaAdornosId,
            'estoque_minimo' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'ADM-OLD',
            'nome' => 'Variação antiga',
            'preco' => 100,
            'custo' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variacaoAdornoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoAdornoId,
            'referencia' => 'ADM-ADORNO',
            'nome' => 'Variação adorno antiga',
            'preco' => 80,
            'custo' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variacaoSubcategoriaAdornoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoSubcategoriaAdornoId,
            'referencia' => 'ADM-ADORNO-FILHO',
            'nome' => 'Variação adorno filha antiga',
            'preco' => 90,
            'custo' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => 3,
                'data_entrada_estoque_atual' => now()->subDays(95)->startOfDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoAdornoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => 5,
                'data_entrada_estoque_atual' => now()->subDays(120)->startOfDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoSubcategoriaAdornoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => 7,
                'data_entrada_estoque_atual' => now()->subDays(140)->startOfDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $response = $this->getJson('/api/v1/dashboard/admin?period=month&compare=1&fresh=1');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['period', 'inicio', 'fim', 'compare', 'deposito_id', 'updated_at'],
                'kpis' => [
                    'vendas_total' => ['value'],
                    'pedidos_total' => ['value'],
                    'ticket_medio' => ['value'],
                    'clientes_unicos' => ['value'],
                ],
                'pedidos_resumo' => [
                    'abertos',
                    'atrasados',
                    'vencem_hoje',
                    'vencem_7_dias',
                    'sem_previsao',
                    'finalizados_periodo',
                ],
                'pedidos_prioritarios' => [
                    [
                        'id',
                        'numero',
                        'cliente',
                        'status',
                        'status_label',
                        'proximo_status',
                        'proximo_status_label',
                        'data_prevista',
                        'dias_para_previsao',
                        'prioridade',
                        'prioridade_label',
                    ],
                ],
                'tempo_estoque_resumo' => [
                    'ate_30' => ['label', 'produtos_qtd', 'quantidade_total'],
                    'de_31_60' => ['label', 'produtos_qtd', 'quantidade_total'],
                    'de_61_90' => ['label', 'produtos_qtd', 'quantidade_total'],
                    'mais_90' => ['label', 'produtos_qtd', 'quantidade_total'],
                ],
                'tempo_estoque' => [
                    [
                        'variacao_id',
                        'produto_nome',
                        'referencia',
                        'quantidade_total',
                        'data_entrada',
                        'dias_em_estoque',
                        'faixa',
                    ],
                ],
                'pendencias' => [
                    'itens_entrega_pendente_qtd',
                    'consignacoes_vencendo_qtd',
                    'pedidos_em_aberto_qtd',
                    'pedidos_por_etapa' => ['criado', 'fabrica', 'recebimento', 'envio_cliente', 'consignacao', 'finalizado'],
                ],
                'series',
            ]);

        $this->assertSame(0, (int) $response->json('meta.compare'));
        $this->assertSame(1, (int) $response->json('pedidos_resumo.abertos'));
        $this->assertSame(1, (int) $response->json('pedidos_resumo.atrasados'));
        $this->assertSame('atrasado', $response->json('pedidos_prioritarios.0.prioridade'));
        $this->assertSame(3, (int) $response->json('tempo_estoque_resumo.mais_90.produtos_qtd'));
        $this->assertSame(15, (int) $response->json('tempo_estoque_resumo.mais_90.quantidade_total'));
        $this->assertContains('Produto Antigo Admin', array_column($response->json('tempo_estoque'), 'produto_nome'));
        $this->assertContains('Adorno Antigo Admin', array_column($response->json('tempo_estoque'), 'produto_nome'));
        $this->assertContains('Adorno Filho Antigo Admin', array_column($response->json('tempo_estoque'), 'produto_nome'));
        $this->assertSame('mais_90', $response->json('tempo_estoque.0.faixa'));

        $responseComDeposito = $this->getJson('/api/v1/dashboard/admin?period=month&fresh=1&deposito_id=' . $depositoId);

        $responseComDeposito->assertOk();
        $this->assertSame($depositoId, (int) $responseComDeposito->json('meta.deposito_id'));
        $this->assertSame(3, (int) $responseComDeposito->json('tempo_estoque_resumo.mais_90.produtos_qtd'));
        $this->assertSame(15, (int) $responseComDeposito->json('tempo_estoque_resumo.mais_90.quantidade_total'));
        $this->assertContains('Adorno Antigo Admin', array_column($responseComDeposito->json('tempo_estoque'), 'produto_nome'));
        $this->assertContains('Adorno Filho Antigo Admin', array_column($responseComDeposito->json('tempo_estoque'), 'produto_nome'));
    }

    public function test_dashboard_admin_oculta_categorias_por_usuario_e_expande_subcategorias(): void
    {
        $admin = $this->autenticar(['dashboard.admin']);

        $depositoId = DB::table('depositos')->insertGetId([
            'nome' => 'Deposito Preferencias',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoriaComumId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Visivel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoriaOcultaId = DB::table('categorias')->insertGetId([
            'nome' => 'Adornos Preferencia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subcategoriaOcultaId = DB::table('categorias')->insertGetId([
            'nome' => 'Adornos Filho Preferencia',
            'categoria_pai_id' => $categoriaOcultaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->criarProdutoEmEstoque('Produto Visivel Preferencia', $categoriaComumId, $depositoId, 2, 95);
        $this->criarProdutoEmEstoque('Produto Oculto Preferencia', $categoriaOcultaId, $depositoId, 3, 120);
        $this->criarProdutoEmEstoque('Produto Oculto Filho Preferencia', $subcategoriaOcultaId, $depositoId, 4, 140);

        $semPreferencia = $this->getJson('/api/v1/dashboard/admin?period=month');
        $semPreferencia->assertOk();
        $this->assertContains('Produto Visivel Preferencia', array_column($semPreferencia->json('tempo_estoque'), 'produto_nome'));
        $this->assertContains('Produto Oculto Preferencia', array_column($semPreferencia->json('tempo_estoque'), 'produto_nome'));
        $this->assertContains('Produto Oculto Filho Preferencia', array_column($semPreferencia->json('tempo_estoque'), 'produto_nome'));
        $produtosMais90Antes = (int) $semPreferencia->json('tempo_estoque_resumo.mais_90.produtos_qtd');
        $quantidadeMais90Antes = (int) $semPreferencia->json('tempo_estoque_resumo.mais_90.quantidade_total');

        $this->getJson('/api/v1/dashboard/admin/preferencias')
            ->assertOk()
            ->assertJson([
                'tempo_estoque_categorias_ocultas' => [],
            ]);

        $this->putJson('/api/v1/dashboard/admin/preferencias', [
            'tempo_estoque_categorias_ocultas' => [$categoriaOcultaId],
        ])
            ->assertOk()
            ->assertJson([
                'tempo_estoque_categorias_ocultas' => [$categoriaOcultaId],
            ]);

        $comPreferencia = $this->getJson('/api/v1/dashboard/admin?period=month');
        $comPreferencia->assertOk();

        $nomes = array_column($comPreferencia->json('tempo_estoque'), 'produto_nome');
        $this->assertContains('Produto Visivel Preferencia', $nomes);
        $this->assertNotContains('Produto Oculto Preferencia', $nomes);
        $this->assertNotContains('Produto Oculto Filho Preferencia', $nomes);
        $this->assertSame(
            $produtosMais90Antes - 2,
            (int) $comPreferencia->json('tempo_estoque_resumo.mais_90.produtos_qtd')
        );
        $this->assertSame(
            $quantidadeMais90Antes - 7,
            (int) $comPreferencia->json('tempo_estoque_resumo.mais_90.quantidade_total')
        );

        $outroAdmin = $this->criarUsuario('Outro Admin Dashboard');
        Sanctum::actingAs($outroAdmin);
        Cache::put('permissoes_usuario_' . $outroAdmin->id, ['dashboard.admin'], now()->addHours(2));

        $outroUsuario = $this->getJson('/api/v1/dashboard/admin?period=month');
        $outroUsuario->assertOk();
        $this->assertContains('Produto Oculto Preferencia', array_column($outroUsuario->json('tempo_estoque'), 'produto_nome'));
        $this->assertContains('Produto Oculto Filho Preferencia', array_column($outroUsuario->json('tempo_estoque'), 'produto_nome'));
    }

    public function test_dashboard_admin_funciona_quando_tabela_de_preferencias_nao_existe(): void
    {
        $this->autenticar(['dashboard.admin']);

        Schema::shouldReceive('hasTable')
            ->with('usuario_preferencias')
            ->andReturnFalse();

        $this->getJson('/api/v1/dashboard/admin?period=month&fresh=1')
            ->assertOk()
            ->assertJson([
                'tempo_estoque' => [],
            ]);

        $this->getJson('/api/v1/dashboard/admin/preferencias')
            ->assertOk()
            ->assertJson([
                'tempo_estoque_categorias_ocultas' => [],
            ]);
    }

    public function test_dashboard_admin_preferencias_retorna_erro_amigavel_quando_tabela_nao_existe(): void
    {
        $this->autenticar(['dashboard.admin']);

        Schema::shouldReceive('hasTable')
            ->with('usuario_preferencias')
            ->andReturnFalse();

        $this->putJson('/api/v1/dashboard/admin/preferencias', [
            'tempo_estoque_categorias_ocultas' => [],
        ])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'Preferências do dashboard ainda não estão disponíveis. Execute as migrations e tente novamente.',
            ]);
    }

    public function test_dashboard_vendedor_respeita_escopo_quando_sem_permissao_visualizar_todos(): void
    {
        $vendedorAutenticado = $this->autenticar(['dashboard.vendedor', 'pedidos.visualizar']);

        $clienteA = $this->criarCliente('Cliente Vendedor A');
        $clienteB = $this->criarCliente('Cliente Vendedor B');

        $outroVendedorId = $this->criarUsuario('Outro Vendedor')->id;

        $pedidoDoAutenticado = $this->criarPedido($clienteA, $vendedorAutenticado->id, now()->subDay(), 150.00);
        $this->criarStatusPedido($pedidoDoAutenticado->id, PedidoStatus::PEDIDO_CRIADO->value, $vendedorAutenticado->id);

        $pedidoDeOutro = $this->criarPedido($clienteB, $outroVendedorId, now()->subDay(), 999.00);
        $this->criarStatusPedido($pedidoDeOutro->id, PedidoStatus::PEDIDO_CRIADO->value, $vendedorAutenticado->id);

        $response = $this->getJson('/api/v1/dashboard/vendedor?period=month');

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('kpis.pedidos_total.value'));
        $this->assertSame(150.0, (float) $response->json('kpis.vendas_total.value'));
    }

    public function test_dashboard_financeiro_retorna_vencidos_e_listas_top(): void
    {
        $usuario = $this->autenticar(['financeiro.dashboard.visualizar']);

        $clienteId = $this->criarCliente('Cliente Financeiro');
        $vendedorId = $this->criarUsuario('Vendedor Financeiro')->id;
        $pedido = $this->criarPedido($clienteId, $vendedorId, now()->subDays(2), 120.00);
        $this->criarStatusPedido($pedido->id, PedidoStatus::PEDIDO_CRIADO->value, $usuario->id);

        DB::table('contas_receber')->insert([
            'pedido_id' => $pedido->id,
            'descricao' => 'Recebível vencido',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 120,
            'valor_liquido' => 120,
            'valor_recebido' => 20,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Financeiro',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contaPagarId = DB::table('contas_pagar')->insertGetId([
            'fornecedor_id' => $fornecedorId,
            'descricao' => 'Pagamento vencido',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 200,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contaFinanceiraId = DB::table('contas_financeiras')->insertGetId([
            'nome' => 'Conta Teste',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => 1,
            'padrao' => 0,
            'saldo_inicial' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contas_pagar_pagamentos')->insert([
            'conta_pagar_id' => $contaPagarId,
            'data_pagamento' => now()->subDays(2)->toDateString(),
            'valor' => 50,
            'forma_pagamento' => 'pix',
            'usuario_id' => $usuario->id,
            'conta_financeira_id' => $contaFinanceiraId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/dashboard/financeiro?compare=1');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['period', 'inicio', 'fim', 'compare', 'deposito_id', 'updated_at'],
                'kpis' => [
                    'receber_vencido_valor' => ['value'],
                    'receber_vencido_qtd' => ['value'],
                    'pagar_vencido_valor' => ['value'],
                    'pagar_vencido_qtd' => ['value'],
                ],
                'pendencias' => [
                    'top_receber_vencidos',
                    'top_pagar_vencidos',
                ],
            ]);

        $this->assertSame(0, (int) $response->json('meta.compare'));
        $this->assertSame(1, (int) $response->json('kpis.receber_vencido_qtd.value'));
        $this->assertSame(1, (int) $response->json('kpis.pagar_vencido_qtd.value'));
    }

    public function test_dashboard_estoque_retorna_kpis_e_pendencias(): void
    {
        $usuario = $this->autenticar(['estoque.movimentacao']);

        $depositoId = DB::table('depositos')->insertGetId([
            'nome' => 'Deposito Teste',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Estoque',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => 'Produto Estoque',
            'id_categoria' => $categoriaId,
            'estoque_minimo' => 10,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-EST-1',
            'nome' => 'Variacao Estoque',
            'preco' => 100,
            'custo' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('estoque_movimentacoes')->insert([
            [
                'id_variacao' => $variacaoId,
                'id_deposito_origem' => null,
                'id_deposito_destino' => $depositoId,
                'id_usuario' => $usuario->id,
                'tipo' => 'entrada',
                'quantidade' => 10,
                'data_movimentacao' => now()->subHours(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_variacao' => $variacaoId,
                'id_deposito_origem' => $depositoId,
                'id_deposito_destino' => null,
                'id_usuario' => $usuario->id,
                'tipo' => 'saida',
                'quantidade' => 3,
                'data_movimentacao' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_variacao' => $variacaoId,
                'id_deposito_origem' => $depositoId,
                'id_deposito_destino' => $depositoId,
                'id_usuario' => $usuario->id,
                'tipo' => 'transferencia',
                'quantidade' => 1,
                'data_movimentacao' => now()->subMinutes(30),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $clienteId = $this->criarCliente('Cliente Estoque');
        $pedido = $this->criarPedido($clienteId, $usuario->id, now()->subDay(), 250.00);
        $this->criarStatusPedido($pedido->id, PedidoStatus::PEDIDO_CRIADO->value, $usuario->id);

        DB::table('pedido_itens')->insert([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacaoId,
            'id_deposito' => $depositoId,
            'quantidade' => 1,
            'entrega_pendente' => 1,
            'preco_unitario' => 250,
            'subtotal' => 250,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('consignacoes')->insert([
            'pedido_id' => $pedido->id,
            'produto_variacao_id' => $variacaoId,
            'deposito_id' => $depositoId,
            'quantidade' => 1,
            'data_envio' => now()->subDay()->toDateString(),
            'prazo_resposta' => now()->addDay()->toDateString(),
            'status' => 'pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/dashboard/estoque?period=7d&fresh=1&deposito_id=' . $depositoId);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['period', 'inicio', 'fim', 'compare', 'deposito_id', 'updated_at'],
                'kpis' => [
                    'estoque_baixo_qtd' => ['value'],
                    'entradas_qtd' => ['value'],
                    'saidas_qtd' => ['value'],
                    'transferencias_qtd' => ['value'],
                ],
                'pendencias' => [
                    'itens_entrega_pendente_qtd',
                    'consignacoes_vencendo_qtd',
                    'ultimas_movimentacoes',
                ],
            ]);

        $this->assertSame(0, (int) $response->json('meta.compare'));
        $this->assertSame(1, (int) $response->json('kpis.estoque_baixo_qtd.value'));
    }

    public function test_dashboard_estoque_permite_acesso_por_alias_de_perfil(): void
    {
        $usuario = $this->criarUsuario('Usuario Perfil Estoque');
        Sanctum::actingAs($usuario);
        Cache::put('perfis_usuario_' . $usuario->id, ['Estoque'], now()->addHours(2));

        $response = $this->getJson('/api/v1/dashboard/estoque?period=7d');

        $response->assertOk();
    }

    public function test_dashboard_series_comercial_retorna_series_e_compare(): void
    {
        $vendedor = $this->autenticar(['dashboard.vendedor', 'pedidos.visualizar']);

        $clienteId = $this->criarCliente('Cliente Serie');

        $pedidoAtual = $this->criarPedido($clienteId, $vendedor->id, now()->subDay(), 100);
        $this->criarStatusPedido($pedidoAtual->id, PedidoStatus::PEDIDO_CRIADO->value, $vendedor->id);

        $pedidoAnterior = $this->criarPedido($clienteId, $vendedor->id, now()->subDays(8), 80);
        $this->criarStatusPedido($pedidoAnterior->id, PedidoStatus::PEDIDO_CRIADO->value, $vendedor->id);

        $response = $this->getJson('/api/v1/dashboard/series/comercial?period=7d&compare=1');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['period', 'inicio', 'fim', 'compare', 'deposito_id', 'updated_at'],
                'kpis',
                'pendencias',
                'series' => [
                    'granularity',
                    'pedidos_serie',
                    'faturamento_serie',
                    'compare' => [
                        'pedidos_serie_previous',
                        'faturamento_serie_previous',
                    ],
                ],
            ]);

        $this->assertSame(1, (int) $response->json('meta.compare'));
    }

    private function autenticar(array $permissoes): Usuario
    {
        $usuario = $this->criarUsuario('Usuario Dashboard');
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHours(2));

        return $usuario;
    }

    private function criarProdutoEmEstoque(
        string $nome,
        int $categoriaId,
        int $depositoId,
        int $quantidade,
        int $diasEmEstoque
    ): void {
        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => $nome,
            'id_categoria' => $categoriaId,
            'estoque_minimo' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-' . uniqid(),
            'nome' => 'Variação ' . $nome,
            'preco' => 100,
            'custo' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => $quantidade,
                'data_entrada_estoque_atual' => now()->subDays($diasEmEstoque)->startOfDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function criarUsuario(string $nome): Usuario
    {
        return Usuario::create([
            'nome' => $nome . ' ' . uniqid(),
            'email' => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
    }

    private function criarCliente(string $nome): int
    {
        return (int) DB::table('clientes')->insertGetId([
            'nome' => $nome,
            'documento' => (string) random_int(10000000000, 99999999999),
            'tipo' => 'pf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function criarPedido(int $clienteId, int $usuarioId, \DateTimeInterface $dataPedido, float $valor): Pedido
    {
        return Pedido::create([
            'id_cliente' => $clienteId,
            'id_usuario' => $usuarioId,
            'tipo' => 'venda',
            'numero_externo' => 'PED-' . strtoupper(substr(uniqid(), -8)),
            'data_pedido' => $dataPedido,
            'valor_total' => $valor,
            'prazo_dias_uteis' => 10,
        ]);
    }

    private function criarStatusPedido(int $pedidoId, string $status, int $usuarioId): void
    {
        PedidoStatusHistorico::create([
            'pedido_id' => $pedidoId,
            'status' => $status,
            'data_status' => now(),
            'usuario_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
