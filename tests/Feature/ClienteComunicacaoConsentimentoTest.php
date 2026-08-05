<?php

namespace Tests\Feature;

use App\Models\AcessoUsuario;
use App\Models\Cliente;
use App\Models\ComunicacaoEventoSaida;
use App\Services\Comunicacao\ClienteConsentimentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClienteComunicacaoConsentimentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_without_consent_payload_does_not_receive_implicit_opt_in(): void
    {
        Http::fake();
        $user = AcessoUsuario::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/clientes', [
            'tipo' => 'pf',
            'nome' => 'Cliente sem opt-in',
            'email' => 'cliente@example.test',
            'telefone' => '91999999999',
        ])->assertCreated();

        $this->assertDatabaseMissing('cliente_comunicacao_consentimentos', ['cliente_id' => $response->json('id')]);
    }

    public function test_explicit_opt_in_is_persisted_and_returned(): void
    {
        Http::fake();
        $user = AcessoUsuario::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/clientes', [
            'tipo' => 'pf',
            'nome' => 'Cliente com opt-in',
            'email' => 'cliente@example.test',
            'bloqueia_email' => false,
            'telefone' => '91999999999',
            'consentimentos' => [[
                'canal' => 'sms',
                'situacao' => 'concedido',
                'origem' => 'cadastro_manual',
                'decidido_em' => now()->toIso8601String(),
            ]],
        ])->assertCreated()->assertJsonPath('consentimentos_comunicacao.0.canal', 'sms');

        $this->assertDatabaseHas('cliente_comunicacao_consentimentos', [
            'cliente_id' => $response->json('id'),
            'canal' => 'sms',
            'situacao' => 'concedido',
        ]);
    }

    public function test_revocation_cancels_pending_item_but_does_not_relabel_item_already_processing(): void
    {
        $user = AcessoUsuario::factory()->create();
        $cliente = Cliente::query()->create(['tipo' => 'pf', 'nome' => 'Cliente revogação']);

        foreach (['pendente', 'processando'] as $status) {
            ComunicacaoEventoSaida::query()->create([
                'cliente_id' => $cliente->id,
                'origem_tipo' => 'pedido',
                'origem_id' => 100,
                'evento_codigo' => 'teste',
                'canal' => 'sms',
                'template_codigo' => 'template_sms',
                'destinatario' => '5591999999999',
                'idempotency_key' => "revogacao:{$status}",
                'correlation_id' => Str::uuid()->toString(),
                'status' => $status,
            ]);
        }

        $this->actingAs($user, 'sanctum');
        app(ClienteConsentimentoService::class)->sincronizar($cliente, [[
            'canal' => 'sms',
            'situacao' => 'revogado',
            'origem' => 'solicitacao_cliente',
            'decidido_em' => now()->toIso8601String(),
        ]]);

        $this->assertDatabaseHas('comunicacao_eventos_saida', [
            'idempotency_key' => 'revogacao:pendente',
            'status' => 'ignorado',
            'erro_codigo' => 'CONSENTIMENTO_REVOGADO',
        ]);
        $this->assertDatabaseHas('comunicacao_eventos_saida', [
            'idempotency_key' => 'revogacao:processando',
            'status' => 'processando',
        ]);
    }
}
