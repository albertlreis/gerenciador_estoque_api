<?php

namespace Tests\Feature;

use App\Models\AcessoUsuario;
use App\Models\Cliente;
use App\Models\ClienteComunicacaoConsentimento;
use App\Models\ComunicacaoJornada;
use App\Models\ContaReceber;
use App\Models\Pedido;
use App\Services\Comunicacao\ComunicacaoOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ComunicacaoJornadasOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_configures_inactive_journey_and_activation_requires_complete_configuration(): void
    {
        $user = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_'.$user->id, ['comunicacao.visualizar', 'comunicacao.templates'], now()->addHour());

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/comunicacao/jornadas', [
            'codigo' => 'pedido_status',
            'nome' => 'Atualização do pedido',
            'tipo' => 'pedido',
            'timezone' => 'America/Belem',
            'eventos' => ['envio_cliente'],
            'canais' => [
                ['canal' => 'email', 'template_codigo' => 'sierra_pedido_status_email', 'ativo' => true],
            ],
        ])->assertCreated()->assertJsonPath('ativo', false);

        $id = $response->json('id');
        $this->patchJson("/api/v1/comunicacao/jornadas/{$id}/ativacao", ['ativo' => true])
            ->assertOk()
            ->assertJsonPath('ativo', true);

        $this->assertDatabaseHas('comunicacao_jornadas', ['id' => $id, 'ativo' => true, 'versao' => 2]);
    }

    public function test_user_without_permission_cannot_manage_journeys(): void
    {
        $user = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_'.$user->id, ['comunicacao.visualizar'], now()->addHour());

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/comunicacao/jornadas', [
            'codigo' => 'blocked',
            'nome' => 'Bloqueada',
            'tipo' => 'pedido',
            'eventos' => [],
            'canais' => [],
        ])->assertForbidden();
    }

    public function test_sms_without_opt_in_is_ignored_before_provider(): void
    {
        [$pedido, $jornada] = $this->pedidoComJornada('sms');

        app(ComunicacaoOutboxService::class)->registrarPedidoStatus($pedido, 'envio_cliente');

        $this->assertDatabaseHas('comunicacao_eventos_saida', [
            'jornada_id' => $jornada->id,
            'canal' => 'sms',
            'status' => 'ignorado',
            'erro_codigo' => 'CONSENTIMENTO_AUSENTE',
            'destinatario' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_opt_in_creates_idempotent_outbox_only_after_transaction_commit(): void
    {
        [$pedido] = $this->pedidoComJornada('whatsapp');
        ClienteComunicacaoConsentimento::query()->create([
            'cliente_id' => $pedido->id_cliente,
            'canal' => 'whatsapp',
            'situacao' => 'concedido',
            'origem' => 'cadastro_manual',
            'decidido_em' => now(),
        ]);

        try {
            DB::transaction(function () use ($pedido): void {
                app(ComunicacaoOutboxService::class)->registrarPedidoStatus($pedido->fresh(['cliente.consentimentosComunicacao']), 'envio_cliente');
                throw new RuntimeException('rollback esperado');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('comunicacao_eventos_saida', 0);

        DB::transaction(function () use ($pedido): void {
            $service = app(ComunicacaoOutboxService::class);
            $service->registrarPedidoStatus($pedido->fresh(['cliente.consentimentosComunicacao']), 'envio_cliente');
            $service->registrarPedidoStatus($pedido->fresh(['cliente.consentimentosComunicacao']), 'envio_cliente');
        });

        $this->assertDatabaseCount('comunicacao_eventos_saida', 1);
        $this->assertDatabaseHas('comunicacao_eventos_saida', ['canal' => 'whatsapp', 'status' => 'pendente']);
    }

    public function test_outbox_uses_store_only_while_real_send_gate_is_disabled(): void
    {
        Http::fake(['http://api-comunicacao.test/api/requests' => Http::response(['data' => ['id' => 1]], 201)]);
        config([
            'services.comms.base_url' => 'http://api-comunicacao.test/api',
            'services.comms.api_key' => 'test-key',
            'services.comms.api_secret' => 'test-secret',
            'comunicacao.real_send_enabled' => false,
            'comunicacao.channels.email' => true,
        ]);
        [$pedido] = $this->pedidoComJornada('email');
        $service = app(ComunicacaoOutboxService::class);
        $service->registrarPedidoStatus($pedido, 'envio_cliente');

        $result = $service->processarPendentes();

        $this->assertSame(1, $result['enviados']);
        $this->assertDatabaseHas('comunicacao_eventos_saida', ['canal' => 'email', 'status' => 'enviado']);
        Http::assertSent(fn ($request) => $request->url() === 'http://api-comunicacao.test/api/requests'
            && data_get($request->data(), 'store_only') === true
            && data_get($request->data(), 'external_id') !== null
            && data_get($request->data(), 'payload.messages.0.client_reference') === "pedido:{$pedido->id}");
    }

    public function test_outbox_stops_after_four_total_attempts_without_fallback(): void
    {
        Http::fake(['http://api-comunicacao.test/api/requests' => Http::response(['message' => 'indisponível'], 503)]);
        config([
            'services.comms.base_url' => 'http://api-comunicacao.test/api',
            'services.comms.api_key' => 'test-key',
            'services.comms.api_secret' => 'test-secret',
            'comunicacao.real_send_enabled' => false,
        ]);
        [$pedido] = $this->pedidoComJornada('email');
        $service = app(ComunicacaoOutboxService::class);
        $service->registrarPedidoStatus($pedido, 'envio_cliente');

        for ($tentativa = 1; $tentativa <= 4; $tentativa++) {
            $service->processarPendentes();
            $evento = \App\Models\ComunicacaoEventoSaida::query()->firstOrFail();
            $this->assertSame($tentativa, $evento->tentativas);

            if ($tentativa < 4) {
                $this->assertSame('pendente', $evento->status);
                $evento->update(['disponivel_em' => now()->subSecond()]);
            }
        }

        $this->assertDatabaseHas('comunicacao_eventos_saida', [
            'canal' => 'email',
            'status' => 'falho',
            'tentativas' => 4,
            'erro_codigo' => 'COMMS_REQUEST_FAILED',
        ]);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => data_get($request->data(), 'payload.messages.0.channel') === 'email');
    }

    public function test_billing_schedule_creates_d_minus_3_d0_d_plus_3_and_d_plus_7_but_skips_paid_accounts(): void
    {
        [$pedido] = $this->pedidoComJornada('email');
        ComunicacaoJornada::query()->where('tipo', 'pedido')->delete();
        $jornada = ComunicacaoJornada::query()->create([
            'codigo' => 'cobranca_email',
            'nome' => 'Cobrança por email',
            'tipo' => 'cobranca',
            'ativo' => true,
            'timezone' => 'America/Belem',
            'agenda' => ['marcos' => [-3, 0, 3, 7], 'hora' => '09:00'],
        ]);
        $jornada->canais()->create([
            'canal' => 'email',
            'template_codigo' => 'template_cobranca_email',
            'ativo' => true,
        ]);
        $hoje = now('America/Belem')->startOfDay();

        foreach ([-3, 0, 3, 7] as $marco) {
            ContaReceber::query()->create([
                'pedido_id' => $pedido->id,
                'descricao' => "Conta {$marco}",
                'numero_documento' => "DOC-{$marco}",
                'data_emissao' => $hoje->toDateString(),
                'data_vencimento' => $hoje->copy()->subDays($marco)->toDateString(),
                'valor_bruto' => 100,
                'valor_liquido' => 100,
                'saldo_aberto' => 100,
                'status' => \App\Enums\ContaStatus::ABERTA,
            ]);
        }
        $contaPaga = ContaReceber::query()->create([
            'pedido_id' => $pedido->id,
            'descricao' => 'Conta paga',
            'numero_documento' => 'DOC-PAGA',
            'data_emissao' => $hoje->toDateString(),
            'data_vencimento' => $hoje->toDateString(),
            'valor_bruto' => 100,
            'valor_liquido' => 100,
            'valor_recebido' => 100,
            'saldo_aberto' => 0,
            'status' => \App\Enums\ContaStatus::PAGA,
        ]);

        $total = app(ComunicacaoOutboxService::class)->agendarCobrancasHoje($hoje);

        $this->assertSame(4, $total);
        foreach ([-3, 0, 3, 7] as $marco) {
            $this->assertDatabaseHas('comunicacao_eventos_saida', [
                'evento_codigo' => "lembrete:{$marco}",
                'canal' => 'email',
                'status' => 'pendente',
            ]);
        }
        $this->assertDatabaseMissing('comunicacao_eventos_saida', [
            'origem_tipo' => 'conta_receber',
            'origem_id' => $contaPaga->id,
        ]);
    }

    public function test_contextual_history_requires_permission_and_queries_canonical_reference(): void
    {
        Http::fake([
            'http://api-comunicacao.test/api/messages*' => Http::response([
                'data' => [['id' => 10, 'client_reference' => 'pedido:99', 'recipient' => 'c***@example.test']],
            ]),
        ]);
        config([
            'services.comms.base_url' => 'http://api-comunicacao.test/api',
            'services.comms.api_key' => 'test-key',
            'services.comms.api_secret' => 'test-secret',
        ]);
        $semPermissao = AcessoUsuario::factory()->create();
        $this->actingAs($semPermissao, 'sanctum')
            ->getJson('/api/v1/comunicacao/historico?tipo=pedido&id=99')
            ->assertForbidden();

        $comPermissao = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_'.$comPermissao->id, ['comunicacao.visualizar'], now()->addHour());
        $this->actingAs($comPermissao, 'sanctum')
            ->getJson('/api/v1/comunicacao/historico?tipo=pedido&id=99')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', 'c***@example.test');

        Http::assertSent(fn ($request) => $request->url() === 'http://api-comunicacao.test/api/messages?client_reference=pedido%3A99&per_page=50');
    }

    private function pedidoComJornada(string $canal): array
    {
        $cliente = Cliente::query()->create([
            'tipo' => 'pf',
            'nome' => 'Cliente Comunicação',
            'email' => 'cliente@example.test',
            'telefone' => '91999999999',
            'whatsapp' => '91999999999',
        ]);
        $user = AcessoUsuario::factory()->create();
        $pedido = Pedido::query()->create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $user->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-OUTBOX-1',
            'data_pedido' => now(),
            'valor_total' => 100,
            'prazo_dias_uteis' => 10,
        ]);
        $jornada = ComunicacaoJornada::query()->create([
            'codigo' => 'pedido_'.$canal,
            'nome' => 'Pedido '.$canal,
            'tipo' => 'pedido',
            'ativo' => true,
            'timezone' => 'America/Belem',
        ]);
        $jornada->eventos()->create(['evento_codigo' => 'envio_cliente']);
        $jornada->canais()->create([
            'canal' => $canal,
            'template_codigo' => 'template_'.$canal,
            'ativo' => true,
        ]);

        return [$pedido->fresh(['cliente.consentimentosComunicacao']), $jornada];
    }
}
