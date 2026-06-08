<?php

namespace Tests\Feature;

use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClienteContaAzulExportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_criacao_de_cliente_nao_falha_quando_exportacao_conta_azul_falha(): void
    {
        Log::spy();

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('cliente')
            ->once()
            ->with(Mockery::type('int'), null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'cliente_criado'
            ))
            ->andThrow(new RuntimeException('Conta Azul indisponivel'));

        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        $cliente = app(ClienteService::class)->criarClienteComEnderecos([
            'tipo' => 'pf',
            'nome' => 'Cliente Exportacao Nao Impeditiva',
            'documento' => null,
            'email' => 'cliente.exportacao.nao.impeditiva@test.com',
        ]);

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'nome' => 'Cliente Exportacao Nao Impeditiva',
        ]);

        $logId = DB::table('auditoria_logs')
            ->where('modulo', 'clientes')
            ->where('acao', 'cliente.created')
            ->where('entity_id', (string) $cliente->id)
            ->latest('id')
            ->value('id');

        $this->assertNotNull($logId);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'nome',
            'new_value' => 'Cliente Exportacao Nao Impeditiva',
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Falha ao disparar exportacao Conta Azul para cliente.', Mockery::on(
                fn (array $contexto) => ($contexto['cliente_id'] ?? null) === $cliente->id
                    && ($contexto['evento'] ?? null) === 'cliente_criado'
                    && ($contexto['erro'] ?? null) === 'Conta Azul indisponivel'
            ));
    }

    public function test_atualizacao_de_cliente_nao_falha_quando_exportacao_conta_azul_falha(): void
    {
        Log::spy();

        $cliente = Cliente::create([
            'tipo' => 'pf',
            'nome' => 'Cliente Antes',
            'documento' => null,
            'email' => 'cliente.antes@test.com',
        ]);

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('cliente')
            ->once()
            ->with($cliente->id, null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'cliente_atualizado'
            ))
            ->andThrow(new RuntimeException('Fila Conta Azul indisponivel'));

        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        $atualizado = app(ClienteService::class)->atualizarClienteComEnderecos($cliente, [
            'tipo' => 'pf',
            'nome' => 'Cliente Depois',
            'documento' => null,
            'email' => 'cliente.depois@test.com',
        ]);

        $this->assertSame($cliente->id, $atualizado->id);
        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'nome' => 'Cliente Depois',
            'email' => 'cliente.depois@test.com',
        ]);

        $logId = DB::table('auditoria_logs')
            ->where('modulo', 'clientes')
            ->where('acao', 'cliente.updated')
            ->where('entity_id', (string) $cliente->id)
            ->latest('id')
            ->value('id');

        $this->assertNotNull($logId);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'nome',
            'old_value' => 'Cliente Antes',
            'new_value' => 'Cliente Depois',
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Falha ao disparar exportacao Conta Azul para cliente.', Mockery::on(
                fn (array $contexto) => ($contexto['cliente_id'] ?? null) === $cliente->id
                    && ($contexto['evento'] ?? null) === 'cliente_atualizado'
                    && ($contexto['erro'] ?? null) === 'Fila Conta Azul indisponivel'
            ));
    }
}
