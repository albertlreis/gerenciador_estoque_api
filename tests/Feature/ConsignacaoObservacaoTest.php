<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsignacaoObservacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_detalhe_retorna_observacao_geral_inclusive_nula(): void
    {
        [$pedido] = $this->criarPedidoConsignado(null, ['consignacoes.visualizar']);

        $this->getJson("/api/v1/consignacoes/pedidos/{$pedido->id}")
            ->assertOk()
            ->assertJsonPath('pedido.observacoes', null);

        $pedido->update(['observacoes' => 'Entregar após as 14h.']);

        $this->getJson("/api/v1/consignacoes/pedidos/{$pedido->id}")
            ->assertOk()
            ->assertJsonPath('pedido.observacoes', 'Entregar após as 14h.');
    }

    public function test_gestor_cria_edita_e_remove_observacao_sem_alterar_dados_comerciais(): void
    {
        [$pedido, $consignacao] = $this->criarPedidoConsignado('Anterior', ['consignacoes.gerenciar']);
        $estadoAntes = $consignacao->only(['status', 'quantidade', 'deposito_id', 'produto_variacao_id']);

        $response = $this->patchJson("/api/v1/consignacoes/pedidos/{$pedido->id}/observacao", [
            'observacoes' => '  Entregar após as 14h.  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('mensagem', 'Observação atualizada com sucesso.')
            ->assertJsonPath('pedido.observacoes', 'Entregar após as 14h.');
        $this->assertSame('Entregar após as 14h.', $pedido->fresh()->observacoes);
        $this->assertSame($estadoAntes, $consignacao->fresh()->only(array_keys($estadoAntes)));

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedido->id}/observacao", [
            'observacoes' => '   ',
        ])
            ->assertOk()
            ->assertJsonPath('pedido.observacoes', null);
        $this->assertNull($pedido->fresh()->observacoes);
    }

    public function test_observacao_valida_tipo_limite_permissao_e_pedido_inexistente(): void
    {
        [$pedido] = $this->criarPedidoConsignado(null, ['consignacoes.visualizar']);

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedido->id}/observacao", ['observacoes' => 'Negado'])
            ->assertForbidden();
        $this->assertNull($pedido->fresh()->observacoes);

        $this->autenticar(['consignacoes.gerenciar']);
        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedido->id}/observacao", ['observacoes' => ['inválido']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('observacoes');
        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedido->id}/observacao", ['observacoes' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('observacoes');
        $this->patchJson('/api/v1/consignacoes/pedidos/999999/observacao', ['observacoes' => null])
            ->assertNotFound();
    }

    public function test_auditoria_registra_somente_metadados_sanitizados(): void
    {
        [$pedido] = $this->criarPedidoConsignado(null, ['consignacoes.gerenciar']);
        $conteudoSensivel = '<script>alert("segredo")</script>\nPortão lateral';

        $this->patchJson("/api/v1/consignacoes/pedidos/{$pedido->id}/observacao", [
            'observacoes' => $conteudoSensivel,
        ])->assertOk();

        $log = DB::table('auditoria_logs')->where('modulo', 'consignacao_observacao')->latest('id')->first();
        $this->assertNotNull($log);
        $serializado = json_encode($log, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($conteudoSensivel, (string) $serializado);
        $this->assertStringContainsString('tamanho_atual', (string) $log->metadata_json);
    }

    private function criarPedidoConsignado(?string $observacoes, array $permissoes): array
    {
        $usuario = $this->autenticar($permissoes);
        $cliente = Cliente::create([
            'nome' => 'Cliente Observação',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Observação']);
        $produto = Produto::create([
            'nome' => 'Produto Observação',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'OBS-'.uniqid(),
            'nome' => 'Variação Observação',
            'preco' => 100,
            'custo' => 60,
        ]);
        $deposito = Deposito::create(['nome' => 'Depósito Observação']);
        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
            'prazo_dias_uteis' => 10,
            'observacoes' => $observacoes,
        ]);
        $consignacao = Consignacao::create([
            'pedido_id' => $pedido->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => 'pendente',
        ]);

        return [$pedido, $consignacao];
    }

    private function autenticar(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Observação '.uniqid(),
            'email' => uniqid('obs-', true).'@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_'.$usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }
}
