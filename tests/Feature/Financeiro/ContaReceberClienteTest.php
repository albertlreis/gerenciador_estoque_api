<?php

namespace Tests\Feature\Financeiro;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContaReceberClienteTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Conta Receber',
            'email' => 'conta.receber.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_store_exige_cliente_quando_pedido_nao_for_informado(): void
    {
        $this->autenticar();

        $this->postJson('/api/v1/financeiro/contas-receber', [
            'descricao' => 'Conta sem cliente',
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_bruto' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors(['cliente_id']);
    }

    public function test_store_preenche_cliente_a_partir_do_pedido(): void
    {
        $this->autenticar();

        $cliente = Cliente::create([
            'nome' => 'Cliente Pedido',
            'documento' => '12345678901',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Pedido']);
        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => auth()->id(),
            'data_pedido' => now()->toDateString(),
            'valor_total' => 300,
            'numero_externo' => 'PED-CLI-001',
        ]);

        $response = $this->postJson('/api/v1/financeiro/contas-receber', [
            'pedido_id' => $pedido->id,
            'cliente_id' => null,
            'descricao' => 'Conta vinculada ao pedido',
            'data_vencimento' => now()->addDays(15)->toDateString(),
            'valor_bruto' => 300,
        ])->assertCreated();

        $contaId = $response->json('data.id');

        $this->assertDatabaseHas('contas_receber', [
            'id' => $contaId,
            'pedido_id' => $pedido->id,
            'cliente_id' => $cliente->id,
        ]);

        $this->assertSame($cliente->id, $response->json('data.cliente.id'));
    }
}
