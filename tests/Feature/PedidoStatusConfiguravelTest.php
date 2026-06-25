<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoStatusDefinicao;
use App\Models\PedidoStatusFluxoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoStatusConfiguravelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 24, 10, 0, 0, config('app.timezone', 'America/Belem')));
        $this->autenticar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_seed_cria_catalogo_equivalente_ao_fluxo_atual(): void
    {
        $this->assertDatabaseHas('pedido_statuses', [
            'codigo' => PedidoStatus::PEDIDO_CRIADO->value,
            'sistema' => true,
            'protegido' => true,
        ]);

        $this->assertDatabaseHas('pedido_status_fluxo_itens', [
            'tipo_fluxo' => 'venda',
            'ordem' => 1,
        ]);

        $this->getJson('/api/v1/pedidos/statuses')
            ->assertOk()
            ->assertJsonFragment(['codigo' => PedidoStatus::FINALIZADO->value]);
    }

    public function test_cria_status_customizado_adiciona_ao_fluxo_e_atualiza_pedido(): void
    {
        $pedido = $this->criarPedidoBase();

        $this->postJson('/api/v1/configuracoes/pedidos/statuses', [
            'codigo' => 'em_producao',
            'nome' => 'Em producao',
            'cor' => '#123456',
            'severidade' => 'info',
            'icone' => 'pi pi-cog',
        ])->assertCreated();

        $this->putJson('/api/v1/configuracoes/pedidos/status-fluxos/venda', [
            'itens' => [
                ['codigo' => 'pedido_criado'],
                ['codigo' => 'em_producao', 'prazo_dias' => 4],
                ['codigo' => 'envio_cliente'],
                ['codigo' => 'entrega_cliente'],
                ['codigo' => 'finalizado'],
            ],
        ])->assertOk();

        $this->getJson("/api/v1/pedidos/{$pedido->id}/status/opcoes")
            ->assertOk()
            ->assertJsonFragment(['value' => 'em_producao', 'label' => 'Em producao']);

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => 'em_producao',
            'data_status' => '2026-06-24',
        ])->assertOk();

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => 'em_producao',
        ]);
    }

    public function test_desativar_status_usado_preserva_historico_e_remove_opcoes_futuras(): void
    {
        $pedido = $this->criarPedidoBase();
        $this->criarStatusCustomizadoNoFluxo('em_conferencia');

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => 'em_conferencia',
        ])->assertOk();

        $this->deleteJson('/api/v1/configuracoes/pedidos/statuses/em_conferencia')
            ->assertOk()
            ->assertJsonPath('deactivated', true);

        $this->assertDatabaseHas('pedido_statuses', [
            'codigo' => 'em_conferencia',
            'ativo' => false,
        ]);

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => 'em_conferencia',
        ]);

        $novoPedido = $this->criarPedidoBase();
        $this->getJson("/api/v1/pedidos/{$novoPedido->id}/status/opcoes")
            ->assertOk()
            ->assertJsonMissing(['value' => 'em_conferencia']);
    }

    public function test_excluir_status_nunca_usado_remove_de_fato(): void
    {
        $this->postJson('/api/v1/configuracoes/pedidos/statuses', [
            'codigo' => 'temporario',
            'nome' => 'Temporario',
        ])->assertCreated();

        $this->deleteJson('/api/v1/configuracoes/pedidos/statuses/temporario')
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('pedido_statuses', [
            'codigo' => 'temporario',
        ]);
    }

    public function test_bloqueia_exclusao_ou_desativacao_de_status_protegido(): void
    {
        $this->deleteJson('/api/v1/configuracoes/pedidos/statuses/finalizado')
            ->assertStatus(422);

        $this->putJson('/api/v1/configuracoes/pedidos/statuses/finalizado', [
            'ativo' => false,
        ])->assertStatus(422);
    }

    public function test_previsao_usa_prazo_da_transicao_configurada(): void
    {
        $pedido = $this->criarPedidoBase('2026-06-20');
        $this->criarStatusCustomizadoNoFluxo('aguardando_separacao', 4);

        $this->getJson("/api/v1/pedidos/{$pedido->id}/status/previsoes")
            ->assertOk()
            ->assertJsonFragment([
                'status' => 'aguardando_separacao',
                'data_prevista' => '2026-06-24',
            ]);
    }

    private function autenticar(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Status Configuravel',
            'email' => uniqid('status-config-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.editar', 'configuracoes.editar']);

        return $usuario;
    }

    private function criarPedidoBase(string $dataCriacao = '2026-06-14'): Pedido
    {
        $usuario = auth()->user();

        $cliente = Cliente::create([
            'nome' => 'Cliente Status Configuravel',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $pedido = Pedido::create([
            'tipo' => 'venda',
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => Carbon::parse($dataCriacao, config('app.timezone', 'America/Belem')),
            'usuario_id' => $usuario->id,
        ]);

        return $pedido;
    }

    private function criarStatusCustomizadoNoFluxo(string $codigo, ?int $prazoDias = null): void
    {
        $status = PedidoStatusDefinicao::query()->create([
            'codigo' => $codigo,
            'nome' => ucfirst(str_replace('_', ' ', $codigo)),
            'cor' => '#123456',
            'severidade' => 'info',
            'icone' => 'pi pi-cog',
            'ativo' => true,
            'sistema' => false,
            'protegido' => false,
        ]);

        $ids = PedidoStatusDefinicao::query()->pluck('id', 'codigo');

        PedidoStatusFluxoItem::query()->where('tipo_fluxo', 'venda')->delete();

        foreach ([
            ['codigo' => 'pedido_criado'],
            ['codigo' => $codigo, 'prazo_dias' => $prazoDias],
            ['codigo' => 'envio_cliente'],
            ['codigo' => 'entrega_cliente'],
            ['codigo' => 'finalizado'],
        ] as $index => $item) {
            PedidoStatusFluxoItem::query()->create([
                'tipo_fluxo' => 'venda',
                'pedido_status_id' => $item['codigo'] === $codigo ? $status->id : $ids[$item['codigo']],
                'ordem' => $index + 1,
                'prazo_dias' => $item['prazo_dias'] ?? null,
                'exige_previsao_manual' => false,
                'ativo' => true,
            ]);
        }
    }
}
