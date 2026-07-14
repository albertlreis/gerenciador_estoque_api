<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportacaoPedidoMovimentacaoEstoqueTest extends TestCase
{
    private ?int $fornecedorId = null;

    protected function tearDown(): void
    {
        if (app()->environment('testing')) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ([
                'produto_entrega_eventos',
                'estoque_movimentacoes',
                'estoque_reservas',
                'produto_entrega_itens',
                'pedido_importacao_itens',
                'pedido_importacoes',
                'pedido_status_historico',
                'pedido_itens',
                'pedidos',
                'estoque',
                'produto_variacoes',
                'produtos',
                'depositos',
                'clientes',
                'fornecedores',
                'categorias',
                'usuarios',
                'acesso_usuarios',
            ] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        parent::tearDown();
    }

    public function test_venda_sem_movimentar_estoque_no_payload_cria_demanda_sem_movimentar(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: false,
            movimentarEstoque: false,
        );
        unset($payload['movimentar_estoque']);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueReserva::query()->where('pedido_id', $pedidoId)->where('status', 'ativa')->count());
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->where('tipo', 'entrada_deposito')->count());
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->where('tipo', 'saida_entrega_cliente')->count());
    }

    public function test_deposito_de_recebimento_prevalece_sobre_fallback_legado_na_confirmacao(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $depositoLegado] = $this->criarContexto();
        $depositoRecebimento = Deposito::create(['nome' => 'Deposito Recebimento Prioritario']);
        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $depositoLegado->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payload['itens'][0]['deposito_recebimento_id'] = $depositoRecebimento->id;

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload)
            ->assertOk();
        $pedidoId = (int) $response->json('id');

        $this->assertDatabaseHas('pedido_itens', [
            'id_pedido' => $pedidoId,
            'id_deposito' => $depositoRecebimento->id,
        ]);
        $dadosConfirmados = json_decode((string) DB::table('pedido_importacao_itens')
            ->where('pedido_id', $pedidoId)
            ->value('dados_confirmados_json'), true);
        $this->assertSame((int) $depositoRecebimento->id, (int) $dadosConfirmados['id_deposito']);
        $this->assertSame((int) $depositoRecebimento->id, (int) $dadosConfirmados['deposito_recebimento_id']);
    }

    public function test_reposicao_manual_exige_chave_e_retry_nao_duplica_pedido(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();
        $payload = $this->payloadImportacao(
            tipo: 'reposicao',
            clienteId: null,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payload['tipo_importacao'] = null;

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $payload['idempotency_key'] = 'reposicao-manual-retry-1';
        $primeira = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload)
            ->assertOk();
        $pedidoId = (int) $primeira->json('id');
        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload)
            ->assertStatus(409)
            ->assertJsonPath('pedido_id', $pedidoId);

        $this->assertSame(1, Pedido::query()->where('id', $pedidoId)->count());
        $this->assertSame(1, PedidoImportacao::query()
            ->where('arquivo_hash', hash('sha256', "manual:{$usuario->id}:{$payload['idempotency_key']}"))
            ->where('pedido_id', $pedidoId)
            ->where('status', 'confirmado')
            ->count());
    }

    public function test_flag_v2_desligada_preserva_movimentacao_da_importacao_legada(): void
    {
        config()->set('pedidos.fluxo_operacional_v2_enabled', false);
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'venda',
                clienteId: $cliente->id,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 2,
                entregue: false,
                movimentarEstoque: true,
            ));

        $response->assertOk();
        $pedidoId = (int) $response->json('id');

        $this->assertSame(2, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()
            ->where('pedido_id', $pedidoId)
            ->where('tipo', 'entrada_deposito')
            ->count());
        $this->assertSame(1, EstoqueReserva::query()
            ->where('pedido_id', $pedidoId)
            ->where('status', 'ativa')
            ->count());

        config()->set('pedidos.fluxo_operacional_v2_enabled', true);
    }

    public function test_campos_legacy_sao_ignorados_e_antecipacao_reserva_sem_reduzir_esperado_da_fabrica(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: false,
            movimentarEstoque: true,
        );
        $payload['itens'][0]['deposito_recebimento_id'] = $deposito->id;
        $payload['itens'][0]['antecipacoes'] = [[
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
        ]];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(2, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(2, (int) $entrega->quantidade_total);
        $this->assertSame(0, (int) $entrega->quantidade_recebida);
        $this->assertSame(2, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
        $this->assertSame(1, EstoqueReserva::query()->where('pedido_id', $pedidoId)->where('status', 'ativa')->count());
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->where('tipo', 'entrada_deposito')->count());
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->where('tipo', 'saida_entrega_cliente')->count());
    }

    public function test_importacao_salva_vendedor_selecionado_quando_usuario_tem_permissao(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();
        $vendedorSelecionado = Usuario::create([
            'nome' => 'Vendedor Selecionado Importacao',
            'email' => uniqid('vendedor-importacao-', false) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.selecionar_vendedor'], now()->addHour());

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payload['pedido']['id_usuario'] = $vendedorSelecionado->id;

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();

        $pedido = \App\Models\Pedido::findOrFail((int) $response->json('id'));
        $this->assertSame((int) $vendedorSelecionado->id, (int) $pedido->id_usuario);
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'usuario_id' => $usuario->id,
        ]);
    }

    public function test_antecipacao_sem_saldo_aborta_importacao_sem_criar_pedido(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();
        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: false,
            movimentarEstoque: false,
        );
        $payload['itens'][0]['antecipacoes'] = [[
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
        ]];

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.antecipacoes.0.quantidade');

        $this->assertSame(0, \App\Models\Pedido::query()->count());
        $this->assertSame(0, ProdutoEntregaItem::query()->count());
    }

    public function test_importacao_sem_vendedor_selecionado_salva_usuario_logado(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();

        $pedido = \App\Models\Pedido::findOrFail((int) $response->json('id'));
        $this->assertSame((int) $usuario->id, (int) $pedido->id_usuario);
    }

    public function test_importacao_bloqueia_vendedor_selecionado_sem_permissao(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();
        $vendedorSelecionado = Usuario::create([
            'nome' => 'Vendedor Bloqueado Importacao',
            'email' => uniqid('vendedor-bloqueado-', false) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payload['pedido']['id_vendedor'] = $vendedorSelecionado->id;

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pedido.id_usuario']);
        $this->assertSame(0, \App\Models\Pedido::query()->count());
    }

    public function test_venda_com_saida_legacy_permanece_pendente_sem_baixar_estoque(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: false,
            movimentarEstoque: true,
            movimentacaoTipo: 'saida',
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(5, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertSame(0, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::EXPEDIDO_CLIENTE)->count());
        $this->assertSame(0, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)->count());
        $this->assertNull(EstoqueReserva::query()->where('pedido_id', $pedidoId)->first());
    }

    public function test_venda_entregue_com_saida_legacy_permanece_pendente(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'venda',
                clienteId: $cliente->id,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 2,
                entregue: true,
                movimentarEstoque: true,
                movimentacaoTipo: 'saida',
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(5, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::EXPEDIDO_CLIENTE)->count());
        $this->assertSame(0, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)->count());
    }

    public function test_venda_entregue_com_entrada_legacy_e_importada_pendente(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: true,
            movimentarEstoque: true,
            movimentacaoTipo: 'entrada',
        );
        $payload['itens'][0]['nome'] = 'MESA APOIO NIX';
        $payload['itens'][0]['ref'] = 'E66008';

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();
        $pedidoId = (int) $response->json('id');
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertSame(0, (int) ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->value('quantidade_recebida'));
    }

    public function test_venda_entregue_com_varios_itens_legacy_cria_demandas_pendentes(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: true,
            movimentarEstoque: true,
            movimentacaoTipo: 'entrada',
        );
        $payload['itens'] = array_map(
            fn ($index) => array_merge($payload['itens'][0], [
                'nome' => "Produto {$index}",
                'ref' => "REF-{$index}",
            ]),
            range(1, 5)
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertOk();
        $pedidoId = (int) $response->json('id');
        $this->assertSame(5, ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->count());
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
    }

    public function test_venda_entregue_sem_movimentacao_cria_demanda_pendente_sem_baixa(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'venda',
                clienteId: $cliente->id,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 2,
                entregue: true,
                movimentarEstoque: false,
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(5, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_CLIENTE->value,
        ]);
    }

    public function test_importacao_permite_numero_externo_repetido(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();
        $numeroExterno = 'XML-DUP-001';

        $payloadA = $this->payloadImportacao(
            tipo: 'reposicao',
            clienteId: null,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payloadA['pedido']['numero_externo'] = $numeroExterno;

        $payloadB = $this->payloadImportacao(
            tipo: 'reposicao',
            clienteId: null,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: false,
            movimentarEstoque: false,
        );
        $payloadB['pedido']['numero_externo'] = $numeroExterno;

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payloadA)
            ->assertOk();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payloadB)
            ->assertOk();

        $this->assertSame(2, \App\Models\Pedido::query()->where('numero_externo', $numeroExterno)->count());
    }

    public function test_reposicao_com_movimentacao_legacy_permanece_pendente(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 4,
                entregue: true,
                movimentarEstoque: true,
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertNull($entrega->id_deposito_origem);
        $this->assertSame($deposito->id, (int) $entrega->id_deposito_destino);
        $this->assertSame(0, (int) $entrega->quantidade_recebida);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertSame(0, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::RECEBIDO_ESTOQUE)->count());
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);
    }

    public function test_reposicao_com_saida_legacy_tambem_permanece_pendente(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 3,
                entregue: false,
                movimentarEstoque: true,
                movimentacaoTipo: 'saida',
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) $entrega->quantidade_recebida);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->where('tipo', 'entrada_deposito')->count());
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->where('tipo', 'saida_entrega_cliente')->count());
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);
    }

    public function test_reposicao_recebida_sem_movimentacao_fica_em_recebiveis_sem_entrada(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 4,
                entregue: true,
                movimentarEstoque: false,
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame($deposito->id, (int) $entrega->id_deposito_destino);
        $this->assertSame(0, (int) $entrega->quantidade_recebida);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);

        $this->getJson('/api/v1/entregas/itens?recebiveis=1&per_page=10')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $entrega->id,
                'quantidade_pendente_recebimento' => 4,
            ]);

        $this->getJson("/api/v1/pedidos/{$pedidoId}/detalhado")
            ->assertOk()
            ->assertJsonPath('data.entrega_itens.0.id', $entrega->id)
            ->assertJsonPath('data.entrega_itens.0.status', ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE)
            ->assertJsonPath('data.entrega_itens.0.quantidade_pendente_recebimento', 4)
            ->assertJsonPath('data.entrega_itens.0.deposito_destino.id', $deposito->id);
    }

    public function test_reposicao_de_fabrica_sem_deposito_aguarda_recebimento_sem_divergencia(): void
    {
        [$usuario, , $categoria, $variacao] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: null,
                quantidade: 1,
                entregue: true,
                movimentarEstoque: true,
            ));

        $response->assertOk();
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $response->json('id'))->firstOrFail();
        $this->assertFalse((bool) $entrega->em_revisao);
        $this->assertNull($entrega->bloqueio_motivo);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $response->json('id'))->count());
    }

    public function test_venda_de_fabrica_sem_deposito_aguarda_recebimento_sem_divergencia(): void
    {
        [$usuario, $cliente, $categoria, $variacao] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $this->payloadImportacao(
                tipo: 'venda',
                clienteId: $cliente->id,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: null,
                quantidade: 1,
                entregue: false,
                movimentarEstoque: true,
            ));

        $response->assertOk();
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $response->json('id'))->firstOrFail();
        $this->assertFalse((bool) $entrega->em_revisao);
        $this->assertNull($entrega->bloqueio_motivo);
    }

    public function test_referencia_ambigua_retorna_validacao_com_produto_e_referencia(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();
        $referenciaAmbigua = 'REF-AMBIGUA-IMPORTACAO';
        $nomeProduto = 'Mesa Jantar Spider';

        $produto = Produto::create([
            'nome' => 'Produto Ambiguo Importacao',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $this->fornecedorId,
            'ativo' => true,
        ]);

        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referenciaAmbigua,
            'nome' => 'Variacao A',
            'preco' => 100,
            'custo' => 50,
        ]);

        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referenciaAmbigua,
            'nome' => 'Variacao B',
            'preco' => 100,
            'custo' => 50,
        ]);

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payload['itens'][0]['nome'] = $nomeProduto;
        $payload['itens'][0]['ref'] = $referenciaAmbigua;
        unset($payload['itens'][0]['id_variacao']);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.selecao_variacao');

        $mensagem = $response->json('errors')['itens.0.selecao_variacao'][0] ?? null;

        $this->assertIsString($mensagem);
        $this->assertStringContainsString("Produto 1: {$nomeProduto}", $mensagem);
        $this->assertStringContainsString("(Ref. {$referenciaAmbigua})", $mensagem);
        $this->assertStringContainsString('múltiplas variações', $mensagem);
        $this->assertCount(2, $response->json('itens.0.variacoes_encontradas'));
        $this->assertEqualsCanonicalizing(
            ['Variacao A', 'Variacao B'],
            collect($response->json('itens.0.variacoes_encontradas'))->pluck('variacao_nome')->all()
        );
    }

    private function criarContexto(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Importacao',
            'email' => uniqid('importacao-', false) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Cache::forget('permissoes_usuario_' . $usuario->id);
        Cache::forget('perfis_usuario_' . $usuario->id);

        $cliente = Cliente::create([
            'nome' => 'Cliente Importacao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Importacao']);
        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor Importacao',
            'status' => 1,
        ]);
        $this->fornecedorId = $fornecedor->id;

        $produto = Produto::create([
            'nome' => 'Produto Importacao',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('IMP-', false),
            'nome' => 'Variacao Importacao',
            'preco' => 100,
            'custo' => 50,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Importacao']);

        return [$usuario, $cliente, $categoria, $variacao, $deposito];
    }

    private function payloadImportacao(
        string $tipo,
        ?int $clienteId,
        int $categoriaId,
        int $variacaoId,
        ?int $depositoId,
        int $quantidade,
        bool $entregue,
        bool $movimentarEstoque,
        ?string $movimentacaoTipo = null
    ): array {
        $data = now()->toDateString();

        $item = [
            'nome' => 'Produto Importacao',
            'ref' => 'REF-IMPORTACAO',
            'quantidade' => $quantidade,
            'valor' => 100,
            'preco_unitario' => 100,
            'custo_unitario' => 50,
            'id_categoria' => $categoriaId,
            'id_variacao' => $variacaoId,
            'id_deposito' => $depositoId,
            'atributos' => [],
        ];

        if ($movimentacaoTipo !== null) {
            $item['movimentacao_estoque_tipo'] = $movimentacaoTipo;
        }

        return [
            'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
            'cliente' => $clienteId ? ['id' => $clienteId] : [],
            'pedido' => [
                'tipo' => $tipo,
                'numero_externo' => uniqid('IMP-', false),
                'id_fornecedor' => $this->fornecedorId,
                'total' => $quantidade * 100,
                'data_pedido' => $data,
                'data_entrega' => $entregue ? $data : null,
                'entregue' => $entregue,
                'previsao_tipo' => 'DIAS_UTEIS',
                'dias_uteis_previstos' => 0,
            ],
            'entregue' => $entregue,
            'movimentar_estoque' => $movimentarEstoque,
            'data_entrega' => $entregue ? $data : null,
            'previsao_tipo' => 'DIAS_UTEIS',
            'dias_uteis_previstos' => 0,
            'itens' => [
                $item,
            ],
        ];
    }
}
