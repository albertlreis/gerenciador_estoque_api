<?php

namespace Tests\Feature;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Services\ContaAzulFinanceiroLocalOfficializationService;
use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\Fornecedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContaAzulFinanceiroPessoasTest extends TestCase
{
    use RefreshDatabase;

    public function test_oficializacao_vincula_cliente_e_fornecedor_do_payload_aninhado(): void
    {
        $this->insertStaging('stg_conta_azul_financeiro', 'titulo-ext-1', [
            'id' => 'titulo-ext-1',
            'descricao' => 'Titulo com cliente',
            'data_vencimento' => '2026-05-10',
            'total' => 200,
            'pago' => 0,
            'nao_pago' => 200,
            'status' => 'OPEN',
            'cliente' => [
                'id' => 'cliente-ext-1',
                'nome' => 'Cliente Conta Azul',
            ],
        ]);
        $this->insertStaging('stg_conta_azul_contas_pagar', 'pagar-ext-1', [
            'id' => 'pagar-ext-1',
            'descricao' => 'Conta com fornecedor',
            'data_vencimento' => '2026-05-11',
            'total' => 300,
            'status' => 'OPEN',
            'fornecedor' => [
                'id' => 'fornecedor-ext-1',
                'nome' => 'Fornecedor Conta Azul',
            ],
        ]);

        app(ContaAzulFinanceiroLocalOfficializationService::class)->oficializar();

        $cliente = Cliente::query()->where('nome', 'Cliente Conta Azul')->firstOrFail();
        $fornecedor = Fornecedor::query()->where('nome', 'Fornecedor Conta Azul')->firstOrFail();

        $this->assertDatabaseHas('contas_receber', [
            'numero_documento' => 'titulo-ext-1',
            'cliente_id' => $cliente->id,
        ]);
        $this->assertDatabaseHas('contas_pagar', [
            'numero_documento' => 'pagar-ext-1',
            'fornecedor_id' => $fornecedor->id,
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::PESSOA,
            'id_externo' => 'cliente-ext-1',
            'id_local' => $cliente->id,
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::FORNECEDOR,
            'id_externo' => 'fornecedor-ext-1',
            'id_local' => $fornecedor->id,
        ]);
    }

    public function test_backfill_e_reoficializacao_preservam_vinculos_existentes(): void
    {
        $clienteManual = Cliente::create(['nome' => 'Cliente Manual', 'tipo' => 'pf']);
        $fornecedorManual = Fornecedor::create(['nome' => 'Fornecedor Manual', 'status' => 1]);
        $clienteBackfill = Cliente::create(['nome' => 'Cliente Backfill', 'tipo' => 'pf']);

        $contaReceberManual = ContaReceber::create([
            'cliente_id' => $clienteManual->id,
            'descricao' => 'Receber manual',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 100,
            'valor_liquido' => 100,
            'valor_recebido' => 0,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
        ]);
        $contaPagarManual = ContaPagar::create([
            'fornecedor_id' => $fornecedorManual->id,
            'descricao' => 'Pagar manual',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 100,
            'status' => 'ABERTA',
        ]);
        $contaReceberSemCliente = ContaReceber::create([
            'descricao' => 'Receber sem cliente',
            'data_vencimento' => '2026-05-12',
            'valor_bruto' => 150,
            'valor_liquido' => 150,
            'valor_recebido' => 0,
            'saldo_aberto' => 150,
            'status' => 'ABERTA',
        ]);

        $this->insertMapping(ContaAzulEntityType::TITULO, 'titulo-manual', $contaReceberManual->id);
        $this->insertMapping(ContaAzulEntityType::CONTA_PAGAR, 'pagar-manual', $contaPagarManual->id);
        $this->insertMapping(ContaAzulEntityType::TITULO, 'titulo-backfill', $contaReceberSemCliente->id);
        $this->insertMapping(ContaAzulEntityType::PESSOA, 'cliente-backfill', $clienteBackfill->id);

        $this->insertStaging('stg_conta_azul_financeiro', 'titulo-manual', [
            'id' => 'titulo-manual',
            'descricao' => 'Receber manual atualizado',
            'data_vencimento' => '2026-05-10',
            'total' => 100,
            'cliente' => ['id' => 'cliente-outro', 'nome' => 'Cliente Outro'],
        ]);
        $this->insertStaging('stg_conta_azul_contas_pagar', 'pagar-manual', [
            'id' => 'pagar-manual',
            'descricao' => 'Pagar manual atualizado',
            'data_vencimento' => '2026-05-10',
            'total' => 100,
            'fornecedor' => ['id' => 'fornecedor-outro', 'nome' => 'Fornecedor Outro'],
        ]);
        $this->insertStaging('stg_conta_azul_financeiro', 'titulo-backfill', [
            'id' => 'titulo-backfill',
            'descricao' => 'Receber sem cliente',
            'data_vencimento' => '2026-05-12',
            'total' => 150,
            'cliente' => ['id' => 'cliente-backfill', 'nome' => 'Cliente Backfill'],
        ]);

        $service = app(ContaAzulFinanceiroLocalOfficializationService::class);
        $service->oficializar();
        $service->backfillPessoasFinanceiras();
        $service->backfillPessoasFinanceiras();

        $this->assertSame($clienteManual->id, $contaReceberManual->fresh()->cliente_id);
        $this->assertSame($fornecedorManual->id, $contaPagarManual->fresh()->fornecedor_id);
        $this->assertSame($clienteBackfill->id, $contaReceberSemCliente->fresh()->cliente_id);
        $this->assertSame(1, Cliente::query()->where('nome', 'Cliente Backfill')->count());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertStaging(string $table, string $externalId, array $payload): void
    {
        DB::table($table)->insert([
            'loja_id' => null,
            'identificador_externo' => $externalId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'hash_payload' => sha1(json_encode($payload)),
            'status_conciliacao' => 'novo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMapping(string $type, string $externalId, int $localId): void
    {
        DB::table('conta_azul_mapeamentos')->insert([
            'loja_id' => null,
            'tipo_entidade' => $type,
            'id_local' => $localId,
            'id_externo' => $externalId,
            'origem_inicial' => 'test',
            'metadata_json' => json_encode(['test' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
