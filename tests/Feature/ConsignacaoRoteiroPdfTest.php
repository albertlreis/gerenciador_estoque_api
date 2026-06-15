<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ClienteEndereco;
use App\Models\Consignacao;
use App\Models\ConsignacaoCompra;
use App\Models\ConsignacaoDevolucao;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Models\Usuario;
use App\Services\EntregaProdutoService;
use App\Services\PdfImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsignacaoRoteiroPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII=';

    public function test_endpoint_de_consignacao_baixa_com_nome_de_roteiro_de_consignacao(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        $response = $this->get("/api/v1/consignacoes/{$pedidoId}/pdf");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-consignacao-{$pedidoId}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_roteiro_do_pedido_usa_nome_de_devolucao_quando_status_finalizado(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('devolvido', PedidoStatus::DEVOLUCAO_CONSIGNACAO);

        $response = $this->get("/api/v1/pedidos/{$pedidoId}/pdf/roteiro");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-devolucao-{$pedidoId}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_endpoint_de_consignacao_permite_forcar_tipo_do_roteiro(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        $response = $this->get("/api/v1/consignacoes/{$pedidoId}/pdf?tipo_roteiro=devolucao");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-devolucao-{$pedidoId}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_roteiro_do_pedido_permite_forcar_tipo_de_consignacao(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('devolvido', PedidoStatus::DEVOLUCAO_CONSIGNACAO);

        $response = $this->get("/api/v1/pedidos/{$pedidoId}/pdf/roteiro?tipo_roteiro=consignacao");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-consignacao-{$pedidoId}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_bloqueia_cancelamento_de_venda_para_usuario_nao_adm(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('comprado', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $dataRespostaAnterior = $consignacao->data_resposta;

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/cancelar-venda");

        $response->assertStatus(403);
        $consignacao->refresh();
        $this->assertSame('comprado', $consignacao->status);
        $this->assertEquals($dataRespostaAnterior, $consignacao->data_resposta);
    }

    public function test_admin_cancela_venda_de_item_consignado(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('comprado', PedidoStatus::CONSIGNADO, ['Administrador']);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/cancelar-venda");

        $response->assertOk();
        $consignacao->refresh();
        $this->assertSame('pendente', $consignacao->status);
        $this->assertNull($consignacao->data_resposta);
    }

    public function test_cancela_devolucao_especifica_estornando_estoque_e_recalculando_status(): void
    {
        [$pedidoId, $variacaoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $this->enviarConsignacaoAoCliente($consignacao);

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/devolucoes", [
            'quantidade' => 1,
            'deposito_id' => $consignacao->deposito_id,
            'observacoes' => 'Retorno teste',
        ]);
        $response->assertOk();

        $consignacao->refresh();
        $this->assertSame('devolvido', $consignacao->status);
        $this->assertSame(1, (int) Estoque::where('id_variacao', $variacaoId)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));

        $devolucao = ConsignacaoDevolucao::where('consignacao_id', $consignacao->id)->firstOrFail();
        $response = $this->deleteJson("/api/v1/consignacoes/{$consignacao->id}/devolucoes/{$devolucao->id}");

        $response->assertOk();
        $consignacao->refresh();
        $this->assertSame('pendente', $consignacao->status);
        $this->assertNull($consignacao->data_resposta);
        $this->assertNotNull($devolucao->fresh()->cancelada_em);
        $this->assertSame(0, (int) Estoque::where('id_variacao', $variacaoId)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));
        $this->assertSame(0, $consignacao->fresh('devolucoes')->quantidadeDevolvida());
    }

    public function test_bloqueia_cancelamento_de_devolucao_antiga_sem_movimentacao_vinculada(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('devolvido', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();

        $devolucao = ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => auth()->id(),
            'quantidade' => 1,
            'observacoes' => 'Registro antigo',
        ]);

        $response = $this->deleteJson("/api/v1/consignacoes/{$consignacao->id}/devolucoes/{$devolucao->id}");

        $response->assertStatus(422);
        $this->assertNull($devolucao->fresh()->cancelada_em);
    }

    public function test_registra_devolucoes_em_massa_com_quantidades_por_item(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacaoA = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacaoA->update(['quantidade' => 3]);
        $consignacaoB = $this->criarConsignacaoParaPedido($pedidoId, $consignacaoA->deposito_id, 2);
        $this->enviarConsignacaoAoCliente($consignacaoA);
        $this->enviarConsignacaoAoCliente($consignacaoB);

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes-em-massa", [
            'deposito_id' => $consignacaoA->deposito_id,
            'observacoes' => 'Devolucao em massa',
            'itens' => [
                ['consignacao_id' => $consignacaoA->id, 'quantidade' => 2],
                ['consignacao_id' => $consignacaoB->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('processados', 2);

        $this->assertSame(2, ConsignacaoDevolucao::where('consignacao_id', $consignacaoA->id)->value('quantidade'));
        $this->assertSame(1, ConsignacaoDevolucao::where('consignacao_id', $consignacaoB->id)->value('quantidade'));
        $this->assertSame('pendente', $consignacaoA->fresh()->status);
        $this->assertSame('pendente', $consignacaoB->fresh()->status);
        $this->assertSame(2, (int) Estoque::where('id_variacao', $consignacaoA->produto_variacao_id)->where('id_deposito', $consignacaoA->deposito_id)->value('quantidade'));
        $this->assertSame(1, (int) Estoque::where('id_variacao', $consignacaoB->produto_variacao_id)->where('id_deposito', $consignacaoB->deposito_id)->value('quantidade'));
    }

    public function test_devolucao_em_massa_nao_processa_parcialmente_quando_item_invalido(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacaoA = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacaoA->update(['quantidade' => 2]);
        $consignacaoB = $this->criarConsignacaoParaPedido($pedidoId, $consignacaoA->deposito_id, 2);
        $this->enviarConsignacaoAoCliente($consignacaoA);
        $this->enviarConsignacaoAoCliente($consignacaoB);

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes-em-massa", [
            'deposito_id' => $consignacaoA->deposito_id,
            'itens' => [
                ['consignacao_id' => $consignacaoA->id, 'quantidade' => 1],
                ['consignacao_id' => $consignacaoB->id, 'quantidade' => 99],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'itens_invalidos']);

        $this->assertSame(0, ConsignacaoDevolucao::count());
        $this->assertSame('pendente', $consignacaoA->fresh()->status);
        $this->assertSame('pendente', $consignacaoB->fresh()->status);
        $this->assertSame(0, (int) Estoque::where('id_variacao', $consignacaoA->produto_variacao_id)->where('id_deposito', $consignacaoA->deposito_id)->value('quantidade'));
        $this->assertSame(0, (int) Estoque::where('id_variacao', $consignacaoB->produto_variacao_id)->where('id_deposito', $consignacaoB->deposito_id)->value('quantidade'));
    }

    public function test_devolucao_em_massa_bloqueia_item_de_outro_pedido(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        [$outroPedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacaoA = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacaoOutroPedido = Consignacao::where('pedido_id', $outroPedidoId)->firstOrFail();
        $this->enviarConsignacaoAoCliente($consignacaoA);

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes-em-massa", [
            'deposito_id' => $consignacaoA->deposito_id,
            'itens' => [
                ['consignacao_id' => $consignacaoA->id, 'quantidade' => 1],
                ['consignacao_id' => $consignacaoOutroPedido->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ConsignacaoDevolucao::count());
        $this->assertSame('pendente', $consignacaoA->fresh()->status);
        $this->assertSame('pendente', $consignacaoOutroPedido->fresh()->status);
    }

    public function test_confirmar_compras_em_massa_finaliza_itens_e_pedido(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacaoA = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacaoB = $this->criarConsignacaoParaPedido($pedidoId, $consignacaoA->deposito_id, 1);
        $this->enviarConsignacaoAoCliente($consignacaoA);
        $this->enviarConsignacaoAoCliente($consignacaoB);

        $response = $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'consignacao_ids' => [$consignacaoA->id, $consignacaoB->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('processados', 2);

        $this->assertSame('comprado', $consignacaoA->fresh()->status);
        $this->assertSame('comprado', $consignacaoB->fresh()->status);
        $this->assertNotNull($consignacaoA->fresh()->data_resposta);
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
    }

    public function test_compras_em_massa_bloqueia_itens_finalizados_sem_alterar_validos(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacaoA = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacaoB = $this->criarConsignacaoParaPedido($pedidoId, $consignacaoA->deposito_id, 1, 'devolvido');

        $response = $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'consignacao_ids' => [$consignacaoA->id, $consignacaoB->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'itens_invalidos']);

        $this->assertSame('pendente', $consignacaoA->fresh()->status);
        $this->assertSame('devolvido', $consignacaoB->fresh()->status);
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
    }

    public function test_compras_em_massa_registra_quantidades_por_item_sem_finalizar_saldo_restante(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacaoA = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacaoA->update(['quantidade' => 3]);
        $consignacaoB = $this->criarConsignacaoParaPedido($pedidoId, $consignacaoA->deposito_id, 2);
        $this->enviarConsignacaoAoCliente($consignacaoA);
        $this->enviarConsignacaoAoCliente($consignacaoB);

        $response = $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'observacoes' => 'Venda parcial',
            'itens' => [
                ['consignacao_id' => $consignacaoA->id, 'quantidade' => 2],
                ['consignacao_id' => $consignacaoB->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('processados', 2);

        $this->assertSame(2, ConsignacaoCompra::where('consignacao_id', $consignacaoA->id)->value('quantidade'));
        $this->assertSame(1, ConsignacaoCompra::where('consignacao_id', $consignacaoB->id)->value('quantidade'));
        $this->assertSame('pendente', $consignacaoA->fresh(['compras', 'devolucoes'])->status);
        $this->assertSame(1, $consignacaoA->fresh(['compras', 'devolucoes'])->quantidadeRestante());
        $this->assertSame('pendente', $consignacaoB->fresh(['compras', 'devolucoes'])->status);
        $this->assertSame(1, $consignacaoB->fresh(['compras', 'devolucoes'])->quantidadeRestante());
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
    }

    public function test_compras_em_massa_bloqueia_quantidade_maior_que_disponivel(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacao->update(['quantidade' => 2]);
        $this->enviarConsignacaoAoCliente($consignacao);

        $response = $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'itens' => [
                ['consignacao_id' => $consignacao->id, 'quantidade' => 3],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'itens_invalidos']);

        $this->assertSame(0, ConsignacaoCompra::count());
        $this->assertSame('pendente', $consignacao->fresh()->status);
    }

    public function test_compras_em_massa_bloqueia_venda_sem_envio(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $this->garantirEstoqueConsignacao($consignacao, 1);

        $response = $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'consignacao_ids' => [$consignacao->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'itens_invalidos']);

        $this->assertSame(0, ConsignacaoCompra::count());
        $this->assertSame(1, (int) Estoque::where('id_variacao', $consignacao->produto_variacao_id)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));
    }

    public function test_registra_envio_posterior_baixa_estoque_uma_vez(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacao->update(['quantidade' => 2]);
        $this->garantirEstoqueConsignacao($consignacao, 2);

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/envio");

        $response->assertOk();
        $this->assertSame(0, (int) Estoque::where('id_variacao', $consignacao->produto_variacao_id)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));
        $this->assertSame(2, $consignacao->fresh()->quantidadeEnviada());
        $this->assertSame(1, EstoqueMovimentacao::where('tipo', 'consignacao_envio')
            ->where('ref_type', 'consignacao')
            ->where('ref_id', $consignacao->id)
            ->count());

        $this->postJson("/api/v1/consignacoes/{$consignacao->id}/envio")
            ->assertStatus(422);
    }

    public function test_confirmar_venda_apos_envio_nao_cria_nova_saida(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $this->enviarConsignacaoAoCliente($consignacao);
        $movimentacoesAntes = EstoqueMovimentacao::where('tipo', 'consignacao_envio')->count();
        $saldoAposEnvio = (int) Estoque::where('id_variacao', $consignacao->produto_variacao_id)->where('id_deposito', $consignacao->deposito_id)->value('quantidade');

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'consignacao_ids' => [$consignacao->id],
        ])->assertOk();

        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::where('tipo', 'consignacao_envio')->count());
        $this->assertSame($saldoAposEnvio, (int) Estoque::where('id_variacao', $consignacao->produto_variacao_id)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));
        $this->assertSame('comprado', $consignacao->fresh()->status);
    }

    public function test_compra_e_devolucao_zerando_saldo_finalizam_item_como_parcial(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacao->update(['quantidade' => 3]);
        $this->enviarConsignacaoAoCliente($consignacao);

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'itens' => [
                ['consignacao_id' => $consignacao->id, 'quantidade' => 1],
            ],
        ])->assertOk();

        $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes-em-massa", [
            'deposito_id' => $consignacao->deposito_id,
            'itens' => [
                ['consignacao_id' => $consignacao->id, 'quantidade' => 2],
            ],
        ])->assertOk();

        $consignacao->refresh();
        $consignacao->load(['compras', 'devolucoes']);
        $this->assertSame('parcial', $consignacao->status);
        $this->assertSame(1, $consignacao->quantidadeComprada());
        $this->assertSame(2, $consignacao->quantidadeDevolvida());
        $this->assertSame(0, $consignacao->quantidadeRestante());
    }

    public function test_admin_cancela_venda_parcial_e_recalcula_saldo(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO, ['Administrador']);
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $consignacao->update(['quantidade' => 2]);
        $this->enviarConsignacaoAoCliente($consignacao);

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'itens' => [
                ['consignacao_id' => $consignacao->id, 'quantidade' => 1],
            ],
        ])->assertOk();

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/cancelar-venda", [
            'motivo' => 'Teste cancelamento parcial',
        ]);

        $response->assertOk();
        $consignacao->refresh();
        $consignacao->load(['compras', 'devolucoes']);
        $this->assertSame('pendente', $consignacao->status);
        $this->assertNull($consignacao->data_resposta);
        $this->assertSame(0, $consignacao->quantidadeComprada());
        $this->assertSame(2, $consignacao->quantidadeRestante());
        $this->assertNotNull(ConsignacaoCompra::where('consignacao_id', $consignacao->id)->value('cancelada_em'));
    }

    public function test_listagem_informa_flags_para_desfazer_consignacao(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();

        $response = $this->getJson('/api/v1/consignacoes');
        $item = collect($response->json('data'))->firstWhere('id', $consignacao->id);

        $response->assertOk();
        $this->assertSame(true, $item['pode_desfazer'] ?? null);
        $this->assertSame(false, $item['tem_historico_comercial'] ?? null);
        $this->assertNull($item['desfazer_bloqueio'] ?? null);
    }

    public function test_listagem_bloqueia_desfazer_com_historico_para_nao_admin(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('comprado', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();

        $response = $this->getJson('/api/v1/consignacoes');
        $item = collect($response->json('data'))->firstWhere('id', $consignacao->id);

        $response->assertOk();
        $this->assertSame(false, $item['pode_desfazer'] ?? null);
        $this->assertSame(true, $item['tem_historico_comercial'] ?? null);
        $this->assertStringContainsString('Apenas administradores', (string) ($item['desfazer_bloqueio'] ?? ''));
    }

    public function test_listagem_libera_desfazer_com_historico_para_admin(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('comprado', PedidoStatus::CONSIGNADO, ['Administrador']);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();

        $response = $this->getJson('/api/v1/consignacoes');
        $item = collect($response->json('data'))->firstWhere('id', $consignacao->id);

        $response->assertOk();
        $this->assertSame(true, $item['pode_desfazer'] ?? null);
        $this->assertSame(true, $item['tem_historico_comercial'] ?? null);
        $this->assertNull($item['desfazer_bloqueio'] ?? null);
    }

    public function test_desfaz_item_pendente_com_reserva_cancelando_item_e_pedido(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $pedidoItemId = (int) $consignacao->pedido_item_id;
        $this->garantirEstoqueConsignacao($consignacao, 1);

        $entregas = app(EntregaProdutoService::class);
        $central = $entregas->criarDemandaConsignacao($consignacao, auth()->id());
        $entregas->reservarItem($central, $consignacao->deposito_id, null, auth()->id(), 'Reserva teste');

        $reservaId = EstoqueReserva::where('pedido_item_id', $pedidoItemId)->value('id');
        $this->assertNotNull($reservaId);
        $this->assertSame('ativa', EstoqueReserva::whereKey($reservaId)->value('status'));

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/desfazer");

        $response->assertOk()
            ->assertJsonPath('consignacoes_desfeitas', 1)
            ->assertJsonPath('itens_removidos', 1)
            ->assertJsonPath('pedido_cancelado', true);

        $this->assertDatabaseMissing('consignacoes', ['id' => $consignacao->id]);
        $this->assertDatabaseMissing('pedido_itens', ['id' => $pedidoItemId]);
        $this->assertSame('cancelada', EstoqueReserva::whereKey($reservaId)->value('status'));
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::CANCELADO->value,
        ]);
    }

    public function test_desfaz_item_enviado_estornando_movimentacao_de_estoque(): void
    {
        [$pedidoId, $variacaoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $pedidoItemId = (int) $consignacao->pedido_item_id;
        $this->enviarConsignacaoAoCliente($consignacao);

        $this->assertSame(0, (int) Estoque::where('id_variacao', $variacaoId)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/desfazer");

        $response->assertOk()
            ->assertJsonPath('pedido_cancelado', true);

        $this->assertDatabaseMissing('consignacoes', ['id' => $consignacao->id]);
        $this->assertDatabaseMissing('pedido_itens', ['id' => $pedidoItemId]);
        $this->assertSame(1, (int) Estoque::where('id_variacao', $variacaoId)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::where('tipo', 'estorno')->where('ref_type', 'estorno')->count());
    }

    public function test_bloqueia_desfazer_consignacao_com_venda_para_usuario_nao_admin(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $this->enviarConsignacaoAoCliente($consignacao);

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'consignacao_ids' => [$consignacao->id],
        ])->assertOk();

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/desfazer");

        $response->assertForbidden();
        $this->assertDatabaseHas('consignacoes', ['id' => $consignacao->id]);
        $this->assertSame(1, $consignacao->fresh(['compras'])->quantidadeComprada());
    }

    public function test_admin_desfaz_consignacao_com_venda_estornando_historico(): void
    {
        [$pedidoId, $variacaoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO, ['Administrador']);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $this->enviarConsignacaoAoCliente($consignacao);

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedidoId}/compras-em-massa", [
            'consignacao_ids' => [$consignacao->id],
        ])->assertOk();

        $response = $this->postJson("/api/v1/consignacoes/{$consignacao->id}/desfazer");

        $response->assertOk()
            ->assertJsonPath('pedido_cancelado', true);

        $this->assertDatabaseMissing('consignacoes', ['id' => $consignacao->id]);
        $this->assertSame(1, (int) Estoque::where('id_variacao', $variacaoId)->where('id_deposito', $consignacao->deposito_id)->value('quantidade'));
    }

    public function test_desfaz_lote_de_consignacao_cancelando_pedido_sem_itens(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        $consignacao = Consignacao::where('pedido_id', $pedidoId)->firstOrFail();
        $this->criarConsignacaoParaPedido($pedidoId, $consignacao->deposito_id, 1);

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/desfazer");

        $response->assertOk()
            ->assertJsonPath('consignacoes_desfeitas', 2)
            ->assertJsonPath('itens_removidos', 2)
            ->assertJsonPath('pedido_cancelado', true);

        $this->assertSame(0, Consignacao::where('pedido_id', $pedidoId)->count());
        $this->assertSame(0, PedidoItem::where('id_pedido', $pedidoId)->count());
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::CANCELADO->value,
        ]);
    }

    public function test_view_do_roteiro_de_consignacao_renderiza_imagem_embutida(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/variacoes/roteiro-consignacao.png', base64_decode(self::PNG_1X1));

        [$pedidoId, $variacaoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        ProdutoVariacaoImagem::create([
            'id_variacao' => $variacaoId,
            'url' => '/storage/produtos/variacoes/roteiro-consignacao.png',
        ]);

        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',
            'statusAtual',
            'consignacoes.deposito',
            'consignacoes.produtoVariacao.imagem',
            'consignacoes.produtoVariacao.produto.imagemPrincipal',
            'consignacoes.produtoVariacao.produto',
            'consignacoes.produtoVariacao.atributos',
            'consignacoes.produtoVariacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

        $pdfImageService = app(PdfImageService::class);
        $pedido->consignacoes->each(function ($consignacao) use ($pdfImageService) {
            $consignacao->setAttribute('pdf_imagem_data_uri', $pdfImageService->fromProdutoVariacao($consignacao->produtoVariacao));
        });

        $html = view('exports.roteiro-consignacao', [
            'pedido' => $pedido,
            'grupos' => $pedido->consignacoes->groupBy(fn($item) => $item->deposito->nome ?? 'Sem depósito'),
            'geradoEm' => now('America/Belem')->format('d/m/Y H:i'),
            'tituloRoteiro' => 'Roteiro de consignação',
        ])->render();

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Rua Consignacao PDF', $html);
        $this->assertStringContainsString('101', $html);
        $this->assertStringContainsString('Sala 1', $html);
        $this->assertStringContainsString('Bairro Consignacao', $html);
        $this->assertStringContainsString('Belem/PA', $html);
        $this->assertStringContainsString('CEP 66000101', $html);
    }

    public function test_view_do_roteiro_de_entrega_renderiza_imagem_embutida_do_produto_como_fallback(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/roteiro-pedido.png', base64_decode(self::PNG_1X1));

        [$pedidoId, $produtoId] = $this->criarPedidoComItem();

        ProdutoImagem::create([
            'id_produto' => $produtoId,
            'url' => 'roteiro-pedido.png',
            'principal' => true,
        ]);

        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',
            'itens.variacao.imagem',
            'itens.variacao.produto.imagemPrincipal',
            'itens.variacao.produto',
            'itens.variacao.atributos',
            'itens.variacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

        $pdfImageService = app(PdfImageService::class);
        $pedido->itens->each(function ($item) use ($pdfImageService) {
            $item->setAttribute('pdf_imagem_data_uri', $pdfImageService->fromProdutoVariacao($item->variacao));
        });

        $html = view('exports.roteiro-pedido', [
            'pedido' => $pedido,
            'grupos' => $pedido->itens->groupBy('id_deposito'),
            'geradoEm' => now('America/Belem')->format('d/m/Y H:i'),
        ])->render();

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Rua Pedido PDF', $html);
        $this->assertStringContainsString('202', $html);
        $this->assertStringContainsString('Sala 2', $html);
        $this->assertStringContainsString('Bairro Pedido', $html);
        $this->assertStringContainsString('Belem/PA', $html);
        $this->assertStringContainsString('CEP 66000202', $html);
    }

    public function test_adiciona_produto_a_consignacao_criando_item_e_demandas(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        [$variacao, $deposito] = $this->criarVariacaoComEstoque();

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/itens", [
            'prazo_resposta' => now()->addDays(7)->toDateString(),
            'itens' => [[
                'id_variacao' => $variacao->id,
                'quantidade' => 2,
                'preco_unitario' => 250.50,
                'id_deposito' => $deposito->id,
                'observacoes' => 'Produto extra',
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('mensagem', 'Produtos adicionados a consignacao com sucesso.');

        $this->assertDatabaseHas('pedido_itens', [
            'id_pedido' => $pedidoId,
            'id_variacao' => $variacao->id,
            'quantidade' => 2,
            'id_deposito' => $deposito->id,
            'observacoes' => 'Produto extra',
        ]);
        $this->assertDatabaseHas('consignacoes', [
            'pedido_id' => $pedidoId,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
            'status' => 'pendente',
        ]);
        $this->assertSame(651.0, (float) Pedido::findOrFail($pedidoId)->valor_total);

        $novaConsignacao = Consignacao::where('pedido_id', $pedidoId)
            ->where('produto_variacao_id', $variacao->id)
            ->firstOrFail();

        $this->assertDatabaseHas('produto_entrega_itens', [
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'consignacao_id' => $novaConsignacao->id,
            'pedido_id' => $pedidoId,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 2,
        ]);
        $this->assertDatabaseHas('produto_entrega_itens', [
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'pedido_id' => $pedidoId,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 2,
        ]);
    }

    public function test_adicionar_produto_a_consignacao_bloqueia_usuario_sem_permissao(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        [$variacao, $deposito] = $this->criarVariacaoComEstoque();

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/itens", [
            'prazo_resposta' => now()->addDays(7)->toDateString(),
            'itens' => [[
                'id_variacao' => $variacao->id,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'id_deposito' => $deposito->id,
            ]],
        ]);

        $response->assertForbidden();
    }

    public function test_vendedor_com_permissao_adiciona_produto_em_pedido_de_outro_vendedor(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        [$variacao, $deposito] = $this->criarVariacaoComEstoque();

        $outroVendedor = Usuario::create([
            'nome' => 'Outro Vendedor',
            'email' => uniqid('outro-vendedor-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Sanctum::actingAs($outroVendedor);
        Cache::put('permissoes_usuario_' . $outroVendedor->id, ['consignacoes.gerenciar'], now()->addHour());

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/itens", [
            'prazo_resposta' => now()->addDays(8)->toDateString(),
            'itens' => [[
                'id_variacao' => $variacao->id,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'id_deposito' => $deposito->id,
            ]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('consignacoes', [
            'pedido_id' => $pedidoId,
            'produto_variacao_id' => $variacao->id,
            'quantidade' => 1,
        ]);
    }

    public function test_adicionar_produto_rejeita_pedido_sem_consignacao(): void
    {
        [$pedidoId] = $this->criarPedidoComItem();
        Cache::put('permissoes_usuario_' . auth()->id(), ['consignacoes.gerenciar'], now()->addHour());
        [$variacao, $deposito] = $this->criarVariacaoComEstoque();

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/itens", [
            'prazo_resposta' => now()->addDays(7)->toDateString(),
            'itens' => [[
                'id_variacao' => $variacao->id,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'id_deposito' => $deposito->id,
            ]],
        ]);

        $response->assertStatus(422);
    }

    private function criarPedidoConsignado(string $statusConsignacao, PedidoStatus $statusPedido, array $perfis = []): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario PDF',
            'email' => uniqid('pdf-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        if ($perfis !== []) {
            Cache::put('perfis_usuario_' . $usuario->id, $perfis, now()->addHour());
        }

        $cliente = Cliente::create([
            'nome' => 'Cliente PDF',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $this->criarEnderecoPrincipal($cliente, [
            'cep' => '66000101',
            'endereco' => 'Rua Consignacao PDF',
            'numero' => '101',
            'complemento' => 'Sala 1',
            'bairro' => 'Bairro Consignacao',
            'cidade' => 'Belem',
            'estado' => 'PA',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria PDF']);
        $produto = Produto::create([
            'nome' => 'Produto PDF',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'PDF-001',
            'nome' => 'Variacao PDF',
            'preco' => 150,
            'custo' => 90,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito PDF']);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 150,
            'prazo_dias_uteis' => 15,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now()->subDay(),
            'usuario_id' => $usuario->id,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => $statusPedido,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);

        $pedidoItem = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 150,
            'subtotal' => 150,
        ]);

        Consignacao::create([
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => $statusConsignacao,
        ]);

        return [$pedido->id, $variacao->id];
    }

    private function criarConsignacaoParaPedido(
        int $pedidoId,
        int $depositoId,
        int $quantidade = 1,
        string $status = 'pendente'
    ): Consignacao {
        $categoria = Categoria::create(['nome' => 'Categoria Consignacao ' . uniqid()]);
        $produto = Produto::create([
            'nome' => 'Produto Consignacao ' . uniqid(),
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'CON-' . uniqid(),
            'nome' => 'Variacao Consignacao',
            'preco' => 150,
            'custo' => 90,
        ]);

        $pedidoItem = PedidoItem::create([
            'id_pedido' => $pedidoId,
            'id_variacao' => $variacao->id,
            'id_deposito' => $depositoId,
            'quantidade' => $quantidade,
            'preco_unitario' => 150,
            'subtotal' => 150 * $quantidade,
        ]);

        return Consignacao::create([
            'pedido_id' => $pedidoId,
            'pedido_item_id' => $pedidoItem->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $depositoId,
            'quantidade' => $quantidade,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => $status,
        ]);
    }

    private function garantirEstoqueConsignacao(Consignacao $consignacao, int $quantidade = 10): void
    {
        Estoque::updateOrCreate(
            [
                'id_variacao' => $consignacao->produto_variacao_id,
                'id_deposito' => $consignacao->deposito_id,
            ],
            ['quantidade' => $quantidade]
        );
    }

    private function enviarConsignacaoAoCliente(Consignacao $consignacao, ?int $quantidade = null): void
    {
        $quantidadeEnvio = $quantidade ?? (int) $consignacao->quantidade;
        $this->garantirEstoqueConsignacao($consignacao, $quantidadeEnvio);

        $payload = [];
        if ($quantidade !== null) {
            $payload['quantidade'] = $quantidade;
        }

        $this->postJson("/api/v1/consignacoes/{$consignacao->id}/envio", $payload)
            ->assertOk();

        $consignacao->refresh();
    }

    private function criarVariacaoComEstoque(int $quantidade = 10): array
    {
        $categoria = Categoria::create(['nome' => 'Categoria Extra ' . uniqid()]);
        $produto = Produto::create([
            'nome' => 'Produto Extra ' . uniqid(),
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'EXT-' . uniqid(),
            'nome' => 'Variacao Extra',
            'preco' => 250.50,
            'custo' => 120,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Extra ' . uniqid()]);

        Estoque::updateOrCreate(
            [
                'id_variacao' => $variacao->id,
                'id_deposito' => $deposito->id,
            ],
            ['quantidade' => $quantidade]
        );

        return [$variacao, $deposito];
    }

    private function criarPedidoComItem(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Pedido PDF',
            'email' => uniqid('pedido-pdf-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $cliente = Cliente::create([
            'nome' => 'Cliente Pedido PDF',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $this->criarEnderecoPrincipal($cliente, [
            'cep' => '66000202',
            'endereco' => 'Rua Pedido PDF',
            'numero' => '202',
            'complemento' => 'Sala 2',
            'bairro' => 'Bairro Pedido',
            'cidade' => 'Belem',
            'estado' => 'PA',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Pedido PDF']);
        $produto = Produto::create([
            'nome' => 'Produto Pedido PDF',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'PED-001',
            'nome' => 'Variacao Pedido PDF',
            'preco' => 150,
            'custo' => 90,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Pedido PDF']);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 150,
            'prazo_dias_uteis' => 15,
        ]);

        PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 150,
            'subtotal' => 150,
        ]);

        return [$pedido->id, $produto->id];
    }

    private function criarEnderecoPrincipal(Cliente $cliente, array $dados): void
    {
        ClienteEndereco::create($dados + [
            'cliente_id' => $cliente->id,
            'principal' => true,
            'fingerprint' => hash('sha256', 'cliente-endereco-' . $cliente->id . '-' . ($dados['endereco'] ?? '')),
        ]);
    }
}
