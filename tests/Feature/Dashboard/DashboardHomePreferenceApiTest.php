<?php

namespace Tests\Feature\Dashboard;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardHomePreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_default_preference_for_new_user(): void
    {
        $this->authenticate();

        $this->getJson('/api/v1/dashboard/home/preferencias')
            ->assertOk()
            ->assertJson([
                'version' => 1,
                'customized' => false,
                'filters' => [],
                'layouts' => ['lg' => [], 'md' => [], 'sm' => []],
                'updated_at' => null,
            ]);
    }

    public function test_persists_partial_filters_and_responsive_layouts(): void
    {
        $user = $this->authenticate();

        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'filters' => ['period' => '7d', 'deposito_id' => 3, 'compare' => 1],
        ])
            ->assertOk()
            ->assertJsonPath('customized', true)
            ->assertJsonPath('filters.period', '7d')
            ->assertJsonPath('filters.deposito_id', 3);

        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'layouts' => [
                'lg' => [
                    ['i' => 'sales.revenue', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
                    ['i' => 'stock.latest_movements', 'x' => 6, 'y' => 2, 'w' => 6, 'h' => 4],
                ],
                'md' => [['i' => 'sales.revenue', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                'sm' => [['i' => 'sales.revenue', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 2]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('filters.period', '7d')
            ->assertJsonCount(2, 'layouts.lg');

        $this->assertDatabaseHas('usuario_preferencias', [
            'usuario_id' => $user->id,
            'chave' => 'dashboard_home_v1',
        ]);
    }

    public function test_preferences_are_isolated_by_user(): void
    {
        $first = $this->authenticate('First');
        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'filters' => ['period' => 'today'],
        ])->assertOk();

        $second = $this->createUser('Second');
        Sanctum::actingAs($second);
        $this->getJson('/api/v1/dashboard/home/preferencias')
            ->assertOk()
            ->assertJsonPath('customized', false);

        Sanctum::actingAs($first);
        $this->getJson('/api/v1/dashboard/home/preferencias')
            ->assertOk()
            ->assertJsonPath('filters.period', 'today');
    }

    public function test_reset_removes_home_and_legacy_admin_preferences(): void
    {
        $user = $this->authenticate();
        $now = now();

        DB::table('usuario_preferencias')->insert([
            [
                'usuario_id' => $user->id,
                'chave' => 'dashboard_home_v1',
                'valor' => json_encode(['version' => 1, 'filters' => ['period' => 'month'], 'layouts' => []]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'usuario_id' => $user->id,
                'chave' => 'dashboard_admin_tempo_estoque_categorias_ocultas',
                'valor' => json_encode([10]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->deleteJson('/api/v1/dashboard/home/preferencias')
            ->assertOk()
            ->assertJsonPath('customized', false);

        $this->assertDatabaseMissing('usuario_preferencias', [
            'usuario_id' => $user->id,
            'chave' => 'dashboard_home_v1',
        ]);
        $this->assertDatabaseMissing('usuario_preferencias', [
            'usuario_id' => $user->id,
            'chave' => 'dashboard_admin_tempo_estoque_categorias_ocultas',
        ]);
    }

    public function test_validates_layout_custom_dates_and_payload_size(): void
    {
        $this->authenticate();

        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'layouts' => [
                'lg' => [
                    ['i' => 'sales.revenue', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
                    ['i' => 'sales.revenue', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['layouts.lg.1.i']);

        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'filters' => ['period' => 'custom', 'inicio' => '2026-07-01'],
        ])->assertStatus(422)->assertJsonValidationErrors(['filters.inicio', 'filters.fim']);

        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'filters' => ['period' => 'month', 'extra' => str_repeat('x', 21000)],
        ])->assertStatus(422)->assertJsonValidationErrors(['payload']);
    }

    public function test_reads_safe_default_and_returns_503_for_mutations_without_table(): void
    {
        $this->authenticate();

        Schema::shouldReceive('hasTable')
            ->with('usuario_preferencias')
            ->andReturnFalse();

        $this->getJson('/api/v1/dashboard/home/preferencias')
            ->assertOk()
            ->assertJsonPath('customized', false);

        $this->patchJson('/api/v1/dashboard/home/preferencias', [
            'filters' => ['period' => 'month'],
        ])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'Preferencias da home ainda nao estao disponiveis. Execute as migrations e tente novamente.',
            ]);
    }

    private function authenticate(string $name = 'Dashboard Home'): Usuario
    {
        $user = $this->createUser($name);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createUser(string $name): Usuario
    {
        return Usuario::create([
            'nome' => $name.' '.uniqid(),
            'email' => strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
    }
}
