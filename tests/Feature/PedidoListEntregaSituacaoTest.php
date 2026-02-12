<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Usuario;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoListEntregaSituacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function autenticar(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Pedidos',
            'email' => uniqid('pedidos-list-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function criarPedido(Usuario $usuario, array $override = []): Pedido
    {
        $cliente = Cliente::create([
            'nome' => 'Cliente Teste',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        return Pedido::create(array_merge([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-' . strtoupper(substr(uniqid(), -6)),
            'data_pedido' => '2026-02-06 10:00:00',
            'valor_total' => 100,
            'prazo_dias_uteis' => 1,
            'data_limite_entrega' => null,
        ], $override));
    }

    private function buscarPedidoNoPayload(array $payload, int $pedidoId): ?array
    {
        foreach (($payload['data'] ?? []) as $pedido) {
            if ((int) ($pedido['id'] ?? 0) === $pedidoId) {
                return $pedido;
            }
        }

        return null;
    }

    public function test_calcula_entrega_prevista_com_dias_uteis_partindo_da_sexta(): void
    {
        Carbon::setTestNow('2026-02-06 09:00:00');
        CarbonImmutable::setTestNow('2026-02-06 09:00:00');
        $usuario = $this->autenticar();
        $pedido = $this->criarPedido($usuario, [
            'data_pedido' => '2026-02-06 10:00:00', // sexta-feira
            'prazo_dias_uteis' => 1,
        ]);

        $response = $this->getJson('/api/v1/pedidos?per_page=50');
        $response->assertOk();

        $pedidoPayload = $this->buscarPedidoNoPayload($response->json(), $pedido->id);
        $this->assertNotNull($pedidoPayload);
        $this->assertSame('2026-02-09', $pedidoPayload['entrega_prevista']); // segunda-feira
        $this->assertSame('No prazo', $pedidoPayload['situacao_entrega']);
    }

    public function test_situacao_entrega_fica_entregue_quando_status_entrega_cliente(): void
    {
        Carbon::setTestNow('2026-02-12 09:00:00');
        CarbonImmutable::setTestNow('2026-02-12 09:00:00');
        $usuario = $this->autenticar();
        $pedido = $this->criarPedido($usuario, [
            'data_pedido' => '2026-02-06 10:00:00',
            'prazo_dias_uteis' => 1,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_CLIENTE->value,
            'data_status' => '2026-02-10 14:00:00',
            'usuario_id' => $usuario->id,
        ]);

        $response = $this->getJson('/api/v1/pedidos?per_page=50');
        $response->assertOk();

        $pedidoPayload = $this->buscarPedidoNoPayload($response->json(), $pedido->id);
        $this->assertNotNull($pedidoPayload);
        $this->assertSame('Entregue', $pedidoPayload['situacao_entrega']);
    }

    public function test_situacao_entrega_fica_atrasado_quando_hoje_maior_que_prevista(): void
    {
        Carbon::setTestNow('2026-02-10 09:00:00');
        CarbonImmutable::setTestNow('2026-02-10 09:00:00');
        $usuario = $this->autenticar();
        $pedido = $this->criarPedido($usuario, [
            'data_pedido' => '2026-02-06 10:00:00', // sexta
            'prazo_dias_uteis' => 1, // prevista na segunda
        ]);

        $response = $this->getJson('/api/v1/pedidos?per_page=50');
        $response->assertOk();

        $pedidoPayload = $this->buscarPedidoNoPayload($response->json(), $pedido->id);
        $this->assertNotNull($pedidoPayload);
        $this->assertSame('Atrasado', $pedidoPayload['situacao_entrega']);
        $this->assertSame(1, (int) ($pedidoPayload['dias_atraso'] ?? 0));
    }
}
