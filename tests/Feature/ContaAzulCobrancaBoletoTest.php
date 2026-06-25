<?php

namespace Tests\Feature;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulCobranca;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulCobrancaService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Models\Cliente;
use App\Models\ContaReceber;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ContaAzulCobrancaBoletoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = Usuario::create([
            'nome' => 'Usuario Boleto Conta Azul',
            'email' => 'boleto-conta-azul-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($this->usuario);
    }

    public function test_gera_boleto_para_conta_aberta_com_titulo_mapeado(): void
    {
        $conta = $this->criarContaReceber();
        $this->criarConexaoAtiva();
        $this->criarMapeamento(ContaAzulEntityType::TITULO, 'titulo-ext-1', $conta->id);

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with(
                'v1/financeiro/eventos-financeiros/contas-a-receber/gerar-cobranca',
                'token-ok',
                Mockery::on(fn (array $payload) => $payload['id_evento_financeiro'] === 'titulo-ext-1'
                    && $payload['tipo'] === 'BOLETO'
                    && $payload['maximo_parcelas'] === 1)
            )
            ->andReturn([
                'status' => 201,
                'body' => json_encode([
                    'id' => 'cobranca-ext-1',
                    'url' => 'https://contaazul.example.test/cobranca/1',
                    'linha_digitavel' => '00190.00009 01234.567890 12345.678901 1 99990000010000',
                ]),
                'json' => [
                    'id' => 'cobranca-ext-1',
                    'url' => 'https://contaazul.example.test/cobranca/1',
                    'linha_digitavel' => '00190.00009 01234.567890 12345.678901 1 99990000010000',
                ],
                'headers' => [],
            ]);
        $this->mockContaAzulClient($client);

        $this->postJson("/api/v1/financeiro/contas-receber/{$conta->id}/boleto-conta-azul")
            ->assertOk()
            ->assertJsonPath('data.cobranca_conta_azul.status', 'emitida')
            ->assertJsonPath('data.cobranca_conta_azul.id_externo', 'cobranca-ext-1')
            ->assertJsonPath('data.cobranca_conta_azul.url', 'https://contaazul.example.test/cobranca/1');

        $this->assertDatabaseHas('conta_azul_cobrancas', [
            'conta_receber_id' => $conta->id,
            'status' => 'emitida',
            'id_externo' => 'cobranca-ext-1',
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::COBRANCA,
            'id_local' => $conta->id,
            'id_externo' => 'cobranca-ext-1',
        ]);
    }

    public function test_exporta_titulo_antes_da_cobranca_quando_nao_ha_mapeamento(): void
    {
        $conta = $this->criarContaReceber();
        $this->criarConexaoAtiva();
        $this->criarMapeamento(ContaAzulEntityType::PESSOA, 'cliente-ext-1', (int) $conta->cliente_id);

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('post')
            ->twice()
            ->andReturnUsing(function (string $uri, string $token, array $payload): array {
                $this->assertSame('token-ok', $token);

                if ($uri === 'v1/financeiro/eventos-financeiros/contas-a-receber') {
                    $this->assertSame('cliente-ext-1', $payload['idCliente'] ?? null);

                    return [
                        'status' => 201,
                        'body' => '{"id":"titulo-ext-novo"}',
                        'json' => ['id' => 'titulo-ext-novo'],
                        'headers' => [],
                    ];
                }

                $this->assertSame('v1/financeiro/eventos-financeiros/contas-a-receber/gerar-cobranca', $uri);
                $this->assertSame('titulo-ext-novo', $payload['id_evento_financeiro'] ?? null);

                return [
                    'status' => 201,
                    'body' => '{"id":"cobranca-ext-nova"}',
                    'json' => ['id' => 'cobranca-ext-nova'],
                    'headers' => [],
                ];
            });
        $this->mockContaAzulClient($client);

        $this->postJson("/api/v1/financeiro/contas-receber/{$conta->id}/boleto-conta-azul")
            ->assertOk()
            ->assertJsonPath('data.cobranca_conta_azul.status', 'emitida');

        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::TITULO,
            'id_local' => $conta->id,
            'id_externo' => 'titulo-ext-novo',
        ]);
        $this->assertDatabaseHas('conta_azul_cobrancas', [
            'conta_receber_id' => $conta->id,
            'id_externo' => 'cobranca-ext-nova',
            'status' => 'emitida',
        ]);
    }

    public function test_nao_gera_boleto_duplicado_quando_ja_emitido(): void
    {
        $conta = $this->criarContaReceber();
        ContaAzulCobranca::create([
            'conta_receber_id' => $conta->id,
            'tipo' => 'BOLETO',
            'status' => 'emitida',
            'id_externo' => 'cobranca-ja-emitida',
            'emitida_em' => now(),
        ]);

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldNotReceive('post');
        $this->mockContaAzulClient($client);

        $this->postJson("/api/v1/financeiro/contas-receber/{$conta->id}/boleto-conta-azul")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conta_receber_id']);
    }

    public function test_bloqueia_boleto_para_conta_paga_cancelada_sem_saldo_ou_sem_cliente(): void
    {
        $this->postJson('/api/v1/financeiro/contas-receber/' . $this->criarContaReceber(['status' => 'PAGA'])->id . '/boleto-conta-azul')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/financeiro/contas-receber/' . $this->criarContaReceber(['status' => 'CANCELADA'])->id . '/boleto-conta-azul')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/financeiro/contas-receber/' . $this->criarContaReceber([
            'valor_bruto' => 0,
            'valor_liquido' => 0,
            'saldo_aberto' => 0,
        ])->id . '/boleto-conta-azul')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['saldo_aberto']);

        $semCliente = ContaReceber::create([
            'descricao' => 'Receber sem cliente',
            'data_vencimento' => '2026-07-10',
            'valor_bruto' => 100,
            'valor_liquido' => 100,
            'valor_recebido' => 0,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
            'forma_recebimento' => 'BOLETO',
        ]);

        $this->postJson("/api/v1/financeiro/contas-receber/{$semCliente->id}/boleto-conta-azul")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cliente_id']);
    }

    public function test_registra_erro_amigavel_para_integracao_sem_api_de_cobranca(): void
    {
        $conta = $this->criarContaReceber();
        $this->criarConexaoAtiva();
        $this->criarMapeamento(ContaAzulEntityType::TITULO, 'titulo-ext-1', $conta->id);

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('post')
            ->once()
            ->andReturn([
                'status' => 403,
                'body' => '{"message":"Forbidden"}',
                'json' => ['message' => 'Forbidden'],
                'headers' => [],
            ]);
        $this->mockContaAzulClient($client);

        $this->postJson("/api/v1/financeiro/contas-receber/{$conta->id}/boleto-conta-azul")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'cobranca_api_indisponivel')
            ->assertJsonPath('message', 'Sua integração Conta Azul não suporta geração de cobranças via API. Verifique se a aplicação foi criada depois de março de 2025 e se a conta possui cobranças habilitadas.');

        $this->assertDatabaseHas('conta_azul_cobrancas', [
            'conta_receber_id' => $conta->id,
            'status' => 'erro',
            'erro_codigo' => '403',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function criarContaReceber(array $overrides = []): ContaReceber
    {
        $cliente = Cliente::create(['nome' => 'Cliente Boleto', 'tipo' => 'pf']);

        return ContaReceber::create(array_merge([
            'cliente_id' => $cliente->id,
            'descricao' => 'Receber boleto Conta Azul',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-10',
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'valor_liquido' => 100,
            'valor_recebido' => 0,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
            'forma_recebimento' => 'BOLETO',
        ], $overrides));
    }

    private function criarConexaoAtiva(): ContaAzulConexao
    {
        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'producao',
        ]);

        ContaAzulToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'token-ok',
            'refresh_token' => 'refresh-ok',
            'expires_at' => now()->addHour(),
        ]);

        return $conexao->fresh('token');
    }

    private function criarMapeamento(string $tipo, string $idExterno, int $idLocal): ContaAzulMapeamento
    {
        return ContaAzulMapeamento::create([
            'tipo_entidade' => $tipo,
            'id_local' => $idLocal,
            'id_externo' => $idExterno,
            'origem_inicial' => 'test',
            'sincronizado_em' => now(),
        ]);
    }

    private function mockContaAzulClient(ContaAzulClient $client): void
    {
        foreach ([
            ContaAzulClient::class,
            ContaAzulConnectionService::class,
            ExportacaoContaAzulService::class,
            ContaAzulCobrancaService::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->app->instance(ContaAzulClient::class, $client);
    }
}
