<?php

namespace Tests\Feature;

use App\Http\Resources\PedidoListResource;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoObservacaoInternaTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_autorizado_cria_edita_e_remove_apenas_observacao_interna(): void
    {
        $pedido = $this->criarPedido(['pedidos.editar'], [
            'observacoes' => 'Texto original do roteiro',
            'valor_total' => 100,
        ]);

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", [
            'observacao_interna' => '  Entregar na Casa Liv.  ',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Observação interna atualizada com sucesso.')
            ->assertJsonPath('data.observacao_interna', 'Entregar na Casa Liv.');

        $pedido->refresh();
        $this->assertSame('Entregar na Casa Liv.', $pedido->observacao_interna);
        $this->assertSame('Texto original do roteiro', $pedido->observacoes);
        $this->assertSame('100.00', $pedido->valor_total);

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", [
            'observacao_interna' => 'Reposição de setembro',
        ])
            ->assertOk()
            ->assertJsonPath('data.observacao_interna', 'Reposição de setembro');

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", [
            'observacao_interna' => '   ',
        ])
            ->assertOk()
            ->assertJsonPath('data.observacao_interna', null);

        $pedido->refresh();
        $this->assertNull($pedido->observacao_interna);
        $this->assertSame('Texto original do roteiro', $pedido->observacoes);
    }

    public function test_valida_payload_permissao_e_pedido_inexistente(): void
    {
        $pedido = $this->criarPedido(['pedidos.visualizar']);

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", [
            'observacao_interna' => 'Negado',
        ])->assertForbidden();
        $this->assertNull($pedido->fresh()->observacao_interna);

        $this->autenticar(['pedidos.editar']);
        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('observacao_interna');
        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", ['observacao_interna' => ['inválido']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('observacao_interna');
        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", ['observacao_interna' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('observacao_interna');
        $this->patchJson('/api/v1/pedidos/999999/observacao-interna', ['observacao_interna' => null])
            ->assertNotFound();
    }

    public function test_auditoria_registra_apenas_metadados_sanitizados(): void
    {
        $pedido = $this->criarPedido(['pedidos.editar']);
        $conteudoSensivel = '<script>alert("segredo")</script> Portão lateral';

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/observacao-interna", [
            'observacao_interna' => $conteudoSensivel,
        ])->assertOk();

        $log = DB::table('auditoria_logs')->where('modulo', 'pedido_observacao_interna')->latest('id')->first();
        $this->assertNotNull($log);
        $serializado = json_encode($log, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($conteudoSensivel, (string) $serializado);
        $this->assertStringContainsString('tamanho_atual', (string) $log->metadata_json);
    }

    public function test_recursos_de_lista_e_detalhe_expoem_os_campos_separadamente(): void
    {
        $pedido = $this->criarPedido(['pedidos.visualizar'], [
            'observacoes' => 'Sai no roteiro',
            'observacao_interna' => 'Somente no sistema',
        ]);

        $pedidoLista = $pedido->fresh([
            'cliente', 'parceiro', 'usuario', 'statusAtual', 'statusPrevisoes',
            'historicoStatus', 'devolucoes', 'entregaItens',
        ]);
        $lista = (new PedidoListResource($pedidoLista))->resolve();

        $this->assertSame('Sai no roteiro', $lista['observacoes']);
        $this->assertSame('Somente no sistema', $lista['observacao_interna']);

        $this->getJson("/api/v1/pedidos/{$pedido->id}/detalhado")
            ->assertOk()
            ->assertJsonPath('data.observacoes', 'Sai no roteiro')
            ->assertJsonPath('data.observacao_interna', 'Somente no sistema');
    }

    private function criarPedido(array $permissoes, array $atributos = []): Pedido
    {
        $usuario = $this->autenticar($permissoes);
        $cliente = Cliente::create([
            'nome' => 'Cliente Observação',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        return Pedido::create(array_merge([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
            'prazo_dias_uteis' => 10,
        ], $atributos));
    }

    private function autenticar(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Observação '.uniqid(),
            'email' => uniqid('pedido-obs-', true).'@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_'.$usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }
}
