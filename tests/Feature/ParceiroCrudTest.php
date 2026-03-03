<?php

namespace Tests\Feature;

use App\Models\Parceiro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParceiroCrudTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Parceiro',
            'email' => 'parceiro-test@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_crud_legado_email_telefone_mantem_compatibilidade_no_root(): void
    {
        $this->autenticar();

        $payload = [
            'nome' => 'Parceiro Legado',
            'tipo' => 'lojista',
            'documento' => '12.345.678/0001-90',
            'email' => 'CONTATO@EMPRESA.COM ',
            'telefone' => '(91) 98036-8321',
            'consultor_nome' => 'Consultor A',
            'nivel_fidelidade' => 'BRONZE',
            'status' => 1,
        ];

        $store = $this->postJson('/api/v1/parceiros', $payload);
        $store->assertCreated();
        $parceiroId = (int) $store->json('data.id');

        $this->assertDatabaseHas('parceiro_contatos', [
            'parceiro_id' => $parceiroId,
            'tipo' => 'email',
            'valor' => 'contato@empresa.com',
            'principal' => 1,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('parceiro_contatos', [
            'parceiro_id' => $parceiroId,
            'tipo' => 'telefone',
            'valor' => '(91) 98036-8321',
            'principal' => 1,
            'deleted_at' => null,
        ]);

        $show = $this->getJson("/api/v1/parceiros/{$parceiroId}");
        $show->assertOk()
            ->assertJsonPath('email', 'contato@empresa.com')
            ->assertJsonPath('telefone', '(91) 98036-8321')
            ->assertJsonPath('consultor_nome', 'Consultor A')
            ->assertJsonPath('nivel_fidelidade', 'BRONZE');

        $update = $this->putJson("/api/v1/parceiros/{$parceiroId}", [
            'email' => 'novo@empresa.com',
            'telefone' => '(91) 98111-1111',
        ]);

        $update->assertOk()
            ->assertJsonPath('data.email', 'novo@empresa.com')
            ->assertJsonPath('data.telefone', '(91) 98111-1111');
    }

    public function test_crud_com_multiplos_contatos_e_reflexo_no_root(): void
    {
        $this->autenticar();

        $store = $this->postJson('/api/v1/parceiros', [
            'nome' => 'Parceiro Multi',
            'tipo' => 'representante',
            'documento' => '12345678901',
            'contatos' => [
                ['tipo' => 'email', 'valor' => 'comercial@multi.com', 'principal' => true],
                ['tipo' => 'email', 'valor' => 'financeiro@multi.com', 'principal' => false],
                ['tipo' => 'telefone', 'valor' => '(91) 98888-0001', 'principal' => true],
                ['tipo' => 'telefone', 'valor' => '(91) 98888-0002', 'principal' => false],
            ],
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.email', 'comercial@multi.com')
            ->assertJsonPath('data.telefone', '(91) 98888-0001');

        $parceiroId = (int) $store->json('data.id');

        $update = $this->putJson("/api/v1/parceiros/{$parceiroId}", [
            'contatos' => [
                ['tipo' => 'email', 'valor' => 'comercial@multi.com', 'principal' => false],
                ['tipo' => 'email', 'valor' => 'financeiro@multi.com', 'principal' => true],
                ['tipo' => 'telefone', 'valor' => '(91) 98888-0001', 'principal' => false],
                ['tipo' => 'telefone', 'valor' => '(91) 98888-0002', 'principal' => true],
            ],
        ]);

        $update->assertOk()
            ->assertJsonPath('data.email', 'financeiro@multi.com')
            ->assertJsonPath('data.telefone', '(91) 98888-0002');
    }

    public function test_backfill_command_migra_legado_sem_duplicar(): void
    {
        $this->autenticar();

        $parceiro = Parceiro::create([
            'nome' => 'Parceiro Backfill',
            'tipo' => 'outro',
            'documento' => '12312312399',
            'email' => 'legacy@teste.com',
            'telefone' => '(91) 99999-1111',
        ]);

        Artisan::call('parceiros:backfill-contatos');
        Artisan::call('parceiros:backfill-contatos');

        $this->assertDatabaseCount('parceiro_contatos', 2);
        $this->assertDatabaseHas('parceiro_contatos', [
            'parceiro_id' => $parceiro->id,
            'tipo' => 'email',
            'valor' => 'legacy@teste.com',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('parceiro_contatos', [
            'parceiro_id' => $parceiro->id,
            'tipo' => 'telefone',
            'valor' => '(91) 99999-1111',
            'deleted_at' => null,
        ]);
    }

    public function test_update_com_contatos_remove_soft_delete_contato_ausente(): void
    {
        $this->autenticar();

        $store = $this->postJson('/api/v1/parceiros', [
            'nome' => 'Parceiro Soft Delete',
            'tipo' => 'outro',
            'documento' => '00988776655',
            'contatos' => [
                ['tipo' => 'email', 'valor' => 'a@soft.com', 'principal' => true],
                ['tipo' => 'telefone', 'valor' => '(91) 97777-7777', 'principal' => true],
            ],
        ]);

        $parceiroId = (int) $store->json('data.id');

        $this->putJson("/api/v1/parceiros/{$parceiroId}", [
            'contatos' => [
                ['tipo' => 'telefone', 'valor' => '(91) 97777-7777', 'principal' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('parceiro_contatos', [
            'parceiro_id' => $parceiroId,
            'tipo' => 'email',
            'valor' => 'a@soft.com',
        ]);

        $this->assertDatabaseMissing('parceiro_contatos', [
            'parceiro_id' => $parceiroId,
            'tipo' => 'email',
            'valor' => 'a@soft.com',
            'deleted_at' => null,
        ]);
    }
}
