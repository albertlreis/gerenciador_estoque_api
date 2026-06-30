<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueReserva;
use App\Models\LocalizacaoEstoque;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstoqueAtualFiltroTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        $suffix = uniqid();

        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'estoque.' . $suffix . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Estoque',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoCom = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-COM-' . $suffix,
            'sku_interno' => 'SKU-COM-' . $suffix,
            'nome' => 'Variacao Com',
            'preco' => 100,
            'custo' => 50,
        ]);

        $variacaoSem = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-SEM-' . $suffix,
            'sku_interno' => 'SKU-SEM-' . $suffix,
            'nome' => 'Variacao Sem',
            'preco' => 120,
            'custo' => 60,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Teste']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoCom->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoSem->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0]
        );

        return [$variacaoCom, $variacaoSem, $deposito, $usuario];
    }

    private function criarVariacao(Produto $produto, string $referencia): ProdutoVariacao
    {
        return ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'sku_interno' => $referencia,
            'nome' => $referencia,
            'preco' => 100,
            'custo' => 50,
        ]);
    }

    private function criarPedido(Usuario $usuario, string $tipo = Pedido::TIPO_VENDA): Pedido
    {
        $cliente = Cliente::create(['nome' => 'Cliente ' . uniqid()]);

        return Pedido::create([
            'tipo' => $tipo,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);
    }

    private function criarEntregaPedido(
        Pedido $pedido,
        ProdutoVariacao $variacao,
        Deposito $deposito,
        array $overrides = []
    ): ProdutoEntregaItem {
        $quantidade = (int) ($overrides['quantidade_total'] ?? 1);
        $pedidoItem = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => $quantidade,
            'preco_unitario' => 100,
            'custo_unitario' => 50,
            'subtotal' => 100 * $quantidade,
        ]);

        return ProdutoEntregaItem::create(array_merge([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => $quantidade,
            'quantidade_reservada' => 0,
            'quantidade_recebida' => 0,
            'quantidade_expedida' => 0,
            'quantidade_entregue' => 0,
            'id_deposito_origem' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
        ], $overrides));
    }

    public function test_filtra_apenas_com_estoque(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
    }

    public function test_filtra_apenas_sem_estoque(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=sem_estoque');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoSem->id, $ids);
        $this->assertNotContains($variacaoCom->id, $ids);
    }

    public function test_compatibilidade_zerados_ainda_funciona(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?zerados=1');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoSem->id, $ids);
        $this->assertNotContains($variacaoCom->id, $ids);
    }

    public function test_aceita_booleanos_textuais_para_estoque_cliente_e_zerados(): void
    {
        $this->seedBase();

        $this->getJson('/api/v1/estoque/atual?estoque_cliente=false&zerados=0')
            ->assertOk();

        $this->getJson('/api/v1/estoque/resumo?estoque_cliente=false&zerados=0')
            ->assertOk();
    }

    public function test_filtro_produto_com_barra_na_referencia_funciona_no_estoque_atual(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $variacaoCom->update(['referencia' => 'MOV/ABC-123']);
        $variacaoSem->update(['referencia' => 'OUTRO-ITEM']);

        $response = $this->getJson('/api/v1/estoque/atual?produto=MOV%2FABC-123');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
    }

    public function test_filtro_produto_por_sku_interno_funciona_no_estoque_atual(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?produto=' . urlencode((string) $variacaoCom->sku_interno));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
        $this->assertSame($variacaoCom->sku_interno, data_get($response->json('data.0'), 'produto_sku_interno'));
    }

    public function test_retorna_dias_sem_venda_desde_entrada_quando_nunca_vendeu(): void
    {
        [$variacaoCom] = $this->seedBase();

        Estoque::query()
            ->where('id_variacao', $variacaoCom->id)
            ->update([
                'quantidade' => 5,
                'ultima_venda_em' => null,
                'data_entrada_estoque_atual' => Carbon::now()->subDays(12)->startOfDay(),
            ]);

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $linha = collect($response->json('data'))->firstWhere('variacao_id', $variacaoCom->id);
        $this->assertNotNull($linha);
        $this->assertGreaterThanOrEqual(12, (int) $linha['dias_sem_venda']);
    }

    public function test_filtro_dias_sem_venda_min_filtra_listagem_e_resumo(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        Estoque::query()
            ->where('id_variacao', $variacaoCom->id)
            ->update([
                'quantidade' => 5,
                'ultima_venda_em' => null,
                'data_entrada_estoque_atual' => Carbon::now()->subDays(20)->startOfDay(),
            ]);

        Estoque::query()
            ->where('id_variacao', $variacaoSem->id)
            ->update([
                'quantidade' => 3,
                'ultima_venda_em' => Carbon::now()->subDays(3)->startOfDay(),
                'data_entrada_estoque_atual' => Carbon::now()->subDays(30)->startOfDay(),
            ]);

        $produtoFiltro = urlencode((string) $variacaoCom->sku_interno);

        $response = $this->getJson('/api/v1/estoque/atual?dias_sem_venda_min=10&estoque_status=com_estoque&produto=' . $produtoFiltro);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);

        $resumo = $this->getJson('/api/v1/estoque/resumo?dias_sem_venda_min=10&estoque_status=com_estoque&produto=' . $produtoFiltro);
        $resumo->assertOk();
        $resumoPayload = $resumo->json('data') ?? $resumo->json();
        $this->assertSame(1, (int) ($resumoPayload['totalProdutos'] ?? 0));
        $this->assertGreaterThanOrEqual(5, (int) ($resumoPayload['totalPecas'] ?? 0));
    }

    public function test_filtro_area_usa_correspondencia_exata_no_estoque_atual(): void
    {
        [$variacaoCom, $variacaoSem, $deposito] = $this->seedBase();

        Estoque::query()
            ->where('id_variacao', $variacaoSem->id)
            ->where('id_deposito', $deposito->id)
            ->update(['quantidade' => 4]);

        $localizacaoAlvo = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => '9-D1',
            'corredor' => 'C1',
            'setor' => 'S1',
            'coluna' => 'A',
            'codigo_composto' => '9-D1-C1-S1-A',
            'ativo' => true,
        ]);
        $localizacaoOutra = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => '9-D10',
            'corredor' => 'C2',
            'setor' => 'S2',
            'coluna' => 'B',
            'codigo_composto' => '9-D10-C2-S2-B',
            'ativo' => true,
        ]);

        Estoque::query()
            ->where('id_variacao', $variacaoCom->id)
            ->where('id_deposito', $deposito->id)
            ->update(['localizacao_id' => $localizacaoAlvo->id]);
        Estoque::query()
            ->where('id_variacao', $variacaoSem->id)
            ->where('id_deposito', $deposito->id)
            ->update(['localizacao_id' => $localizacaoOutra->id]);

        $response = $this->getJson('/api/v1/estoque/atual?area=' . urlencode('9-D1'));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
    }

    public function test_filtro_localizacao_id_filtra_estoque_atual_e_resumo(): void
    {
        [$variacaoCom, $variacaoSem, $deposito] = $this->seedBase();

        Estoque::query()
            ->where('id_variacao', $variacaoSem->id)
            ->where('id_deposito', $deposito->id)
            ->update(['quantidade' => 4]);

        $localizacaoAlvo = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'setor' => '8',
            'coluna' => 'D',
            'nivel' => '3',
            'codigo_composto' => '8-D-3',
            'ativo' => true,
        ]);
        $localizacaoOutra = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'setor' => '9',
            'coluna' => 'A',
            'codigo_composto' => '9-A',
            'ativo' => true,
        ]);

        Estoque::query()
            ->where('id_variacao', $variacaoCom->id)
            ->where('id_deposito', $deposito->id)
            ->update(['localizacao_id' => $localizacaoAlvo->id]);
        Estoque::query()
            ->where('id_variacao', $variacaoSem->id)
            ->where('id_deposito', $deposito->id)
            ->update(['localizacao_id' => $localizacaoOutra->id]);

        $response = $this->getJson('/api/v1/estoque/atual?localizacao_id=' . $localizacaoAlvo->id);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);

        $resumo = $this->getJson('/api/v1/estoque/resumo?localizacao_id=' . $localizacaoAlvo->id);
        $resumo->assertOk();
        $payload = $resumo->json('data') ?? $resumo->json();

        $this->assertSame(1, (int) ($payload['totalProdutos'] ?? 0));
        $this->assertSame(5, (int) ($payload['totalPecas'] ?? 0));
    }

    public function test_filtro_estoque_cliente_retorna_reservas_ativas_de_pedido(): void
    {
        [$variacaoCom, $variacaoSem, $deposito, $usuario] = $this->seedBase();

        $cliente = Cliente::create(['nome' => 'Cliente Reserva']);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);

        EstoqueReserva::create([
            'id_variacao' => $variacaoCom->id,
            'id_deposito' => $deposito->id,
            'pedido_id' => $pedido->id,
            'quantidade' => 2,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
        ]);

        $response = $this->getJson('/api/v1/estoque/atual?estoque_cliente=1');
        $response->assertOk();

        $linhas = collect($response->json('data'));
        $ids = $linhas->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);

        $linha = $linhas->firstWhere('variacao_id', $variacaoCom->id);
        $this->assertSame(2, (int) ($linha['quantidade_reservada_cliente'] ?? 0));
        $this->assertSame(2, (int) ($linha['quantidade_estoque_cliente_total'] ?? 0));
        $this->assertSame(0, (int) ($linha['quantidade_cliente_aguardando_estoque'] ?? 0));
        $this->assertSame(0, (int) ($linha['quantidade_cliente_pendente_entrega'] ?? 0));
    }

    public function test_filtro_estoque_cliente_status_segmenta_estados_e_exclui_entregues_cancelados_e_reposicao(): void
    {
        [$variacaoReservada, $variacaoAguardando, $deposito, $usuario] = $this->seedBase();
        $produto = $variacaoReservada->produto;

        $variacaoPendenteEntrega = $this->criarVariacao($produto, 'CLIENTE-PENDENTE-' . uniqid());
        $variacaoEntregue = $this->criarVariacao($produto, 'CLIENTE-ENTREGUE-' . uniqid());
        $variacaoCancelada = $this->criarVariacao($produto, 'CLIENTE-CANCELADA-' . uniqid());
        $variacaoReposicao = $this->criarVariacao($produto, 'CLIENTE-REPOSICAO-' . uniqid());

        foreach ([$variacaoPendenteEntrega, $variacaoEntregue, $variacaoCancelada, $variacaoReposicao] as $variacao) {
            Estoque::updateOrCreate(
                ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
                ['quantidade' => 0]
            );
        }

        $pedidoVenda = $this->criarPedido($usuario);
        $pedidoReposicao = $this->criarPedido($usuario, Pedido::TIPO_REPOSICAO);

        $this->criarEntregaPedido($pedidoVenda, $variacaoAguardando, $deposito, [
            'quantidade_total' => 3,
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
        ]);

        $entregaReservada = $this->criarEntregaPedido($pedidoVenda, $variacaoReservada, $deposito, [
            'quantidade_total' => 2,
            'quantidade_reservada' => 2,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);
        EstoqueReserva::create([
            'id_variacao' => $variacaoReservada->id,
            'id_deposito' => $deposito->id,
            'pedido_id' => $pedidoVenda->id,
            'pedido_item_id' => $entregaReservada->pedido_item_id,
            'quantidade' => 2,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
        ]);

        $this->criarEntregaPedido($pedidoVenda, $variacaoPendenteEntrega, $deposito, [
            'quantidade_total' => 2,
            'quantidade_expedida' => 2,
            'quantidade_entregue' => 1,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);
        $this->criarEntregaPedido($pedidoVenda, $variacaoEntregue, $deposito, [
            'quantidade_total' => 2,
            'quantidade_expedida' => 2,
            'quantidade_entregue' => 2,
            'status' => ProdutoEntregaItem::STATUS_ENTREGUE,
        ]);
        $this->criarEntregaPedido($pedidoVenda, $variacaoCancelada, $deposito, [
            'quantidade_total' => 2,
            'status' => ProdutoEntregaItem::STATUS_CANCELADO,
        ]);
        $this->criarEntregaPedido($pedidoReposicao, $variacaoReposicao, $deposito, [
            'quantidade_total' => 2,
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
        ]);

        $aguardando = $this->getJson('/api/v1/estoque/atual?estoque_cliente_status=aguardando_estoque');
        $aguardando->assertOk();
        $idsAguardando = collect($aguardando->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoAguardando->id, $idsAguardando);
        $this->assertNotContains($variacaoReservada->id, $idsAguardando);
        $this->assertNotContains($variacaoPendenteEntrega->id, $idsAguardando);

        $reservado = $this->getJson('/api/v1/estoque/atual?estoque_cliente_status=reservado');
        $reservado->assertOk();
        $idsReservado = collect($reservado->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoReservada->id, $idsReservado);
        $this->assertNotContains($variacaoAguardando->id, $idsReservado);
        $this->assertNotContains($variacaoPendenteEntrega->id, $idsReservado);

        $pendenteEntrega = $this->getJson('/api/v1/estoque/atual?estoque_cliente_status=pendente_entrega');
        $pendenteEntrega->assertOk();
        $idsPendenteEntrega = collect($pendenteEntrega->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoPendenteEntrega->id, $idsPendenteEntrega);
        $this->assertNotContains($variacaoAguardando->id, $idsPendenteEntrega);
        $this->assertNotContains($variacaoReservada->id, $idsPendenteEntrega);

        $todos = $this->getJson('/api/v1/estoque/atual?estoque_cliente_status=todos_pendentes');
        $todos->assertOk();
        $linhas = collect($todos->json('data'));
        $idsTodos = $linhas->pluck('variacao_id')->all();
        $this->assertContains($variacaoAguardando->id, $idsTodos);
        $this->assertContains($variacaoReservada->id, $idsTodos);
        $this->assertContains($variacaoPendenteEntrega->id, $idsTodos);
        $this->assertNotContains($variacaoEntregue->id, $idsTodos);
        $this->assertNotContains($variacaoCancelada->id, $idsTodos);
        $this->assertNotContains($variacaoReposicao->id, $idsTodos);

        $linhaAguardando = $linhas->firstWhere('variacao_id', $variacaoAguardando->id);
        $linhaReservada = $linhas->firstWhere('variacao_id', $variacaoReservada->id);
        $linhaPendenteEntrega = $linhas->firstWhere('variacao_id', $variacaoPendenteEntrega->id);

        $this->assertSame(3, (int) ($linhaAguardando['quantidade_estoque_cliente_total'] ?? 0));
        $this->assertSame(3, (int) ($linhaAguardando['quantidade_cliente_aguardando_estoque'] ?? 0));
        $this->assertSame(2, (int) ($linhaReservada['quantidade_estoque_cliente_total'] ?? 0));
        $this->assertSame(2, (int) ($linhaReservada['quantidade_reservada_cliente'] ?? 0));
        $this->assertSame(1, (int) ($linhaPendenteEntrega['quantidade_estoque_cliente_total'] ?? 0));
        $this->assertSame(1, (int) ($linhaPendenteEntrega['quantidade_cliente_pendente_entrega'] ?? 0));
    }

    public function test_estoque_atual_retorna_campos_de_outlet(): void
    {
        [$variacaoCom] = $this->seedBase();
        $suffix = uniqid('', true);

        $motivo = OutletMotivo::create([
            'slug' => 'baixa_rotatividade_' . str_replace('.', '_', $suffix),
            'nome' => 'Baixa rotatividade',
            'ativo' => true,
        ]);
        $forma = OutletFormaPagamento::create([
            'slug' => 'pix-estoque-atual-' . $suffix,
            'nome' => 'PIX Estoque Atual',
            'ativo' => true,
        ]);
        $outlet = ProdutoVariacaoOutlet::create([
            'produto_variacao_id' => $variacaoCom->id,
            'motivo_id' => $motivo->id,
            'quantidade' => 2,
            'quantidade_restante' => 2,
        ]);
        ProdutoVariacaoOutletPagamento::create([
            'produto_variacao_outlet_id' => $outlet->id,
            'forma_pagamento_id' => $forma->id,
            'percentual_desconto' => 10,
            'max_parcelas' => 1,
        ]);

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $linha = collect($response->json('data'))->firstWhere('variacao_id', $variacaoCom->id);
        $this->assertSame(2, (int) $linha['estoque_outlet_total']);
        $this->assertSame(2, (int) $linha['outlet_restante_total']);
        $this->assertTrue((bool) $linha['is_outlet']);
    }
}
