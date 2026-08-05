<?php

namespace Tests\Feature;

use App\Enums\ContaStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ClienteComunicacaoConsentimento;
use App\Models\ComunicacaoJornada;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\AcessoUsuario;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Services\Comunicacao\ComunicacaoOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComunicacaoSierraTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualizar_status_pedido_registra_outbox_sem_chamada_externa_na_transacao(): void
    {
        Http::fake();

        $user = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_' . $user->id, ['pedidos.editar', 'contas_receber.criar'], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Comunicacao',
            'email' => 'cliente@example.test',
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $user->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-COMM-1',
            'data_pedido' => now(),
            'valor_total' => 100.0,
            'prazo_dias_uteis' => 10,
        ]);
        $jornada = ComunicacaoJornada::query()->create([
            'codigo' => 'pedido_status_email',
            'nome' => 'Status do pedido por email',
            'tipo' => 'pedido',
            'ativo' => true,
            'timezone' => 'America/Belem',
        ]);
        $jornada->eventos()->create(['evento_codigo' => 'envio_cliente']);
        $jornada->canais()->create([
            'canal' => 'email',
            'template_codigo' => 'sierra_pedido_status_email',
            'ativo' => true,
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Comunicacao']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Comunicacao', 'status' => 1]);
        $produto = Produto::create([
            'nome' => 'Produto Comunicacao',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'COMM-001',
            'nome' => 'Variacao Comunicacao',
            'preco' => 100,
            'custo' => 50,
        ]);

        ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 1,
            'quantidade_reservada' => 1,
            'quantidade_expedida' => 1,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => 'envio_cliente',
            'observacoes' => 'Teste',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('comunicacao_eventos_saida', [
            'origem_tipo' => 'pedido',
            'origem_id' => $pedido->id,
            'evento_codigo' => 'envio_cliente',
            'canal' => 'email',
            'status' => 'pendente',
        ]);
        Http::assertNothingSent();
    }

    public function test_cobranca_so_entra_no_outbox_no_marco_agendado_e_com_opt_in(): void
    {
        Http::fake();

        $user = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_' . $user->id, ['contas_receber.criar'], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Cobranca',
            'whatsapp' => '91989413333',
        ]);
        ClienteComunicacaoConsentimento::query()->create([
            'cliente_id' => $cliente->id,
            'canal' => 'whatsapp',
            'situacao' => 'concedido',
            'origem' => 'teste_automatizado',
            'decidido_em' => now(),
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $user->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-COMM-2',
            'data_pedido' => now(),
            'valor_total' => 100.0,
            'prazo_dias_uteis' => 10,
        ]);

        $this->actingAs($user, 'sanctum');
        $jornada = ComunicacaoJornada::query()->create([
            'codigo' => 'cobranca_whatsapp',
            'nome' => 'Cobrança por WhatsApp',
            'tipo' => 'cobranca',
            'ativo' => true,
            'timezone' => 'America/Belem',
            'agenda' => ['marcos' => [0], 'hora' => '09:00'],
        ]);
        $jornada->canais()->create([
            'canal' => 'whatsapp',
            'template_codigo' => 'sierra_cobranca_whatsapp',
            'ativo' => true,
        ]);

        $response = $this->postJson('/api/v1/financeiro/contas-receber', [
            'descricao' => 'Teste',
            'numero_documento' => 'DOC-1',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now('America/Belem')->toDateString(),
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'valor_recebido' => 0,
            'status' => ContaStatus::ABERTA->value,
            'pedido_id' => $pedido->id,
        ])->assertCreated();

        $this->assertDatabaseCount('comunicacao_eventos_saida', 0);
        app(ComunicacaoOutboxService::class)->agendarCobrancasHoje(now('America/Belem'));

        $this->assertDatabaseHas('comunicacao_eventos_saida', [
            'origem_tipo' => 'conta_receber',
            'origem_id' => $response->json('data.id'),
            'evento_codigo' => 'lembrete:0',
            'canal' => 'whatsapp',
            'status' => 'pendente',
        ]);
        Http::assertNothingSent();
    }
}
