<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsuarioPreferenciasApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_retorna_preferencia_vazia_quando_usuario_ainda_nao_salvou(): void
    {
        $this->autenticar();

        $this->getJson('/api/v1/preferencias/telas/pedidos')
            ->assertOk()
            ->assertJson([
                'version' => 1,
                'filters' => [],
                'tables' => [],
            ]);
    }

    public function test_salva_e_atualiza_preferencia_de_tela(): void
    {
        $usuario = $this->autenticar();

        $this->patchJson('/api/v1/preferencias/telas/pedidos', [
            'filters' => [
                'status' => 'aberto',
                'periodo' => ['2026-07-01', '2026-07-31'],
            ],
            'tables' => [
                'main' => [
                    'hidden_columns' => ['cliente', 'valor_total', 'cliente'],
                    'rows' => 25,
                    'sort' => ['field' => 'data', 'order' => 'desc'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJson([
                'version' => 1,
                'filters' => [
                    'status' => 'aberto',
                    'periodo' => ['2026-07-01', '2026-07-31'],
                ],
                'tables' => [
                    'main' => [
                        'hidden_columns' => ['cliente', 'valor_total'],
                        'rows' => 25,
                        'sort' => ['field' => 'data', 'order' => 'desc'],
                    ],
                ],
            ]);

        $this->assertDatabaseHas('usuario_preferencias', [
            'usuario_id' => $usuario->id,
            'chave' => 'screen:pedidos',
        ]);

        $this->patchJson('/api/v1/preferencias/telas/pedidos', [
            'tables' => [
                'main' => [
                    'hidden_columns' => ['valor_total'],
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/preferencias/telas/pedidos')
            ->assertOk()
            ->assertJsonPath('filters.status', 'aberto')
            ->assertJsonPath('tables.main.hidden_columns', ['valor_total']);
    }

    public function test_preferencias_sao_isoladas_por_usuario(): void
    {
        $primeiro = $this->autenticar('Primeiro Usuario');

        $this->patchJson('/api/v1/preferencias/telas/estoque.movimentacoes', [
            'filters' => ['deposito' => 10],
        ])->assertOk();

        $segundo = $this->criarUsuario('Segundo Usuario');
        Sanctum::actingAs($segundo);

        $this->getJson('/api/v1/preferencias/telas/estoque.movimentacoes')
            ->assertOk()
            ->assertJsonPath('filters', []);

        Sanctum::actingAs($primeiro);

        $this->getJson('/api/v1/preferencias/telas/estoque.movimentacoes')
            ->assertOk()
            ->assertJsonPath('filters.deposito', 10);
    }

    public function test_remove_preferencia_da_tela(): void
    {
        $this->autenticar();

        $this->patchJson('/api/v1/preferencias/telas/produtos', [
            'filters' => ['nome' => 'mesa'],
            'tables' => ['main' => ['hidden_columns' => ['categoria']]],
        ])->assertOk();

        $this->deleteJson('/api/v1/preferencias/telas/produtos')
            ->assertOk()
            ->assertJson([
                'version' => 1,
                'filters' => [],
                'tables' => [],
            ]);

        $this->getJson('/api/v1/preferencias/telas/produtos')
            ->assertOk()
            ->assertJsonPath('filters', [])
            ->assertJsonPath('tables', []);
    }

    public function test_valida_screen_key_e_tamanho_do_payload(): void
    {
        $this->autenticar();

        $this->getJson('/api/v1/preferencias/telas/tela%20invalida')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screenKey']);

        $this->patchJson('/api/v1/preferencias/telas/pedidos', [
            'filters' => [
                'texto' => str_repeat('x', 21000),
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payload']);
    }

    public function test_retorna_erro_amigavel_quando_tabela_nao_existe_em_mutacoes(): void
    {
        $this->autenticar();

        Schema::shouldReceive('hasTable')
            ->with('usuario_preferencias')
            ->andReturnFalse();

        $this->getJson('/api/v1/preferencias/telas/pedidos')
            ->assertOk()
            ->assertJsonPath('filters', []);

        $this->patchJson('/api/v1/preferencias/telas/pedidos', [
            'filters' => ['status' => 'aberto'],
        ])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'Preferencias de usuario ainda nao estao disponiveis. Execute as migrations e tente novamente.',
            ]);
    }

    private function autenticar(string $nome = 'Usuario Preferencias'): Usuario
    {
        $usuario = $this->criarUsuario($nome);
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function criarUsuario(string $nome): Usuario
    {
        return Usuario::create([
            'nome' => $nome . ' ' . uniqid(),
            'email' => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
    }
}
