<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoStatusPrevisaoAtualizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 24, 14, 30, 45, config('app.timezone', 'America/Belem')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_exige_previsao_para_status_editavel(): void
    {
        [, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::EMBARQUE_FABRICA->value,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['data_prevista']);
    }

    public function test_salva_status_e_previsao_na_mesma_requisicao(): void
    {
        [$usuario, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::EMBARQUE_FABRICA->value,
            'observacoes' => 'Status com previsao informada',
            'data_prevista' => '2026-06-10',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data_prevista', '2026-06-10');

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::EMBARQUE_FABRICA->value,
            'observacoes' => 'Status com previsao informada',
            'usuario_id' => $usuario->id,
        ]);

        $this->assertDatabaseHas('pedido_status_previsoes', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::EMBARQUE_FABRICA->value,
            'data_prevista' => '2026-06-10',
            'usuario_id' => $usuario->id,
        ]);
    }

    public function test_salva_data_status_informada_no_historico(): void
    {
        [, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
            'data_status' => '2026-06-20',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data_status', '2026-06-20');

        $historico = PedidoStatusHistorico::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', PedidoStatus::ENVIADO_FABRICA->value)
            ->firstOrFail();

        $this->assertSame('2026-06-20', $historico->data_status->toDateString());
        $this->assertSame('14:30:45', $historico->data_status->format('H:i:s'));
    }

    public function test_status_sem_previsao_editavel_nao_exige_data_prevista(): void
    {
        [, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data_status', '2026-06-24');

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
        ]);

        $historico = PedidoStatusHistorico::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', PedidoStatus::ENVIADO_FABRICA->value)
            ->firstOrFail();

        $this->assertSame('2026-06-24', $historico->data_status->toDateString());

        $this->assertDatabaseMissing('pedido_status_previsoes', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
        ]);
    }

    public function test_bloqueia_data_status_futura(): void
    {
        [, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
            'data_status' => '2026-06-25',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['data_status']);
    }

    public function test_bloqueia_data_status_anterior_ao_ultimo_status(): void
    {
        [, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
            'data_status' => '2026-06-13',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['data_status']);
    }

    public function test_mantem_bloqueio_para_status_duplicado(): void
    {
        [, $pedido] = $this->criarPedidoBase();

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::PEDIDO_CRIADO->value,
        ]);

        $response->assertStatus(422);
    }

    public function test_mantem_bloqueio_para_regressao_de_status(): void
    {
        [$usuario, $pedido] = $this->criarPedidoBase();

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::NOTA_EMITIDA,
            'data_status' => now()->addMinute(),
            'usuario_id' => $usuario->id,
        ]);

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::ENVIADO_FABRICA->value,
        ]);

        $response->assertStatus(422);
    }

    private function criarPedidoBase(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Status Previsao',
            'email' => uniqid('status-previsao-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.editar']);

        $cliente = Cliente::create([
            'nome' => 'Cliente Status Previsao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now()->subDays(10),
            'usuario_id' => $usuario->id,
        ]);

        return [$usuario, $pedido];
    }
}
