<?php

namespace Tests\Feature;

use App\Enums\ContaStatus;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\AcessoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComunicacaoSierraTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualizar_status_pedido_dispara_chamada_para_api_comunicacao(): void
    {
        Http::fake();

        $user = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_' . $user->id, ['pedidos.editar', 'contas_receber.criar'], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Comunicacao',
            'email' => 'cliente@example.test',
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $user->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-COMM-1',
            'data_pedido' => now(),
            'valor_total' => 100.0,
            'prazo_dias_uteis' => 10,
        ]);

        $this->actingAs($user, 'sanctum');

        config([
            'services.comms.base_url' => 'http://api-comunicacao:8002/api',
            'services.comms.api_key' => 'key-test',
            'services.comms.api_secret' => 'secret-test',
            'comunicacao.templates.pedido_status_email' => 'sierra_pedido_status_email',
        ]);

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => 'envio_cliente',
            'observacoes' => 'Teste',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'http://api-comunicacao:8002/api/requests'
                && $request->hasHeader('X-API-KEY', 'key-test')
                && $request->hasHeader('X-API-SECRET', 'secret-test')
                && data_get($data, 'source') === 'sierra'
                && data_get($data, 'payload.messages.0.channel') === 'email'
                && data_get($data, 'payload.messages.0.to_email') === 'cliente@example.test'
                && data_get($data, 'payload.messages.0.template_code') === 'sierra_pedido_status_email';
        });
    }

    public function test_criacao_conta_receber_dispara_cobranca_sms_ou_whatsapp(): void
    {
        Http::fake();

        $user = AcessoUsuario::factory()->create();
        Cache::put('permissoes_usuario_' . $user->id, ['contas_receber.criar'], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Cobranca',
            'whatsapp' => '91989413333',
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $user->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-COMM-2',
            'data_pedido' => now(),
            'valor_total' => 100.0,
            'prazo_dias_uteis' => 10,
        ]);

        $this->actingAs($user, 'sanctum');

        config([
            'services.comms.base_url' => 'http://api-comunicacao:8002/api',
            'services.comms.api_key' => 'key-test',
            'services.comms.api_secret' => 'secret-test',
            'comunicacao.templates.cobranca_sms' => 'sierra_cobranca_sms',
            'comunicacao.templates.cobranca_whatsapp' => 'sierra_cobranca_whatsapp',
        ]);

        $this->postJson('/api/v1/financeiro/contas-receber', [
            'descricao' => 'Teste',
            'numero_documento' => 'DOC-1',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'valor_recebido' => 0,
            'status' => ContaStatus::ABERTA->value,
            'pedido_id' => $pedido->id,
        ])->assertCreated();

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'http://api-comunicacao:8002/api/requests') {
                return false;
            }

            $data = $request->data();
            $messages = (array) data_get($data, 'payload.messages', []);
            if (count($messages) === 0) {
                return false;
            }

            $channels = array_map(fn ($m) => $m['channel'] ?? null, $messages);

            return in_array('sms', $channels, true) || in_array('whatsapp', $channels, true);
        });
    }
}

