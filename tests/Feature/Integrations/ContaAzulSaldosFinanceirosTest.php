<?php

namespace Tests\Feature\Integrations;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Services\ContaAzulAutoMatchService;
use App\Integrations\ContaAzul\Services\ContaAzulFinanceiroLocalOfficializationService;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use App\Services\FinanceiroExtratoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContaAzulSaldosFinanceirosTest extends TestCase
{
    use RefreshDatabase;

    public function test_conciliacao_de_saldo_conta_azul_normaliza_moeda_pt_br(): void
    {
        $conta = $this->criarConta(['nome' => 'Banco do Brasil']);
        $this->mapearConta($conta, 'ca-bb');

        $resultado = app(ContaAzulAutoMatchService::class)->matchSaldoContaFinanceira(
            (object) ['identificador_externo' => 'ca-bb'],
            [
                'saldoAtual' => 'R$ 200.000,12',
                'consultado_em' => '2026-06-24 10:30:00',
            ],
            null
        );

        $conta->refresh();

        $this->assertSame('conciliado', $resultado['status']);
        $this->assertSame('200000.12', $conta->saldo_atual);
        $this->assertSame('2026-06-24 10:30:00', $conta->saldo_atual_em?->format('Y-m-d H:i:s'));
        $this->assertSame('R$ 200.000,12', $conta->meta_json['conta_azul_saldo']['saldoAtual']);
    }

    public function test_oficializacao_de_saldo_conta_azul_normaliza_e_preserva_payload(): void
    {
        $conta = $this->criarConta([
            'nome' => 'Banco do Brasil',
            'meta_json' => ['origem' => 'manual'],
        ]);
        $this->mapearConta($conta, 'ca-bb-oficializacao');

        $this->insertStagingSaldo('ca-bb-oficializacao', [
            'id_conta_financeira' => 'ca-bb-oficializacao',
            'saldo_atual' => '200.000,12',
            'dataConsulta' => '2026-06-24 11:00:00',
        ]);

        $resultado = app(ContaAzulFinanceiroLocalOfficializationService::class)->oficializar(null);
        $conta->refresh();

        $this->assertSame(1, $resultado['saldos_contas_financeiras']['atualizados']);
        $this->assertSame('200000.12', $conta->saldo_atual);
        $this->assertSame('manual', $conta->meta_json['origem']);
        $this->assertSame('200.000,12', $conta->meta_json['conta_azul_saldo']['saldo_atual']);
        $this->assertSame('2026-06-24 11:00:00', $conta->saldo_atual_em?->format('Y-m-d H:i:s'));
    }

    public function test_extrato_financeiro_confere_saldo_final_com_creditos_debitos_transferencias_e_cancelados(): void
    {
        $conta = $this->criarConta([
            'saldo_inicial' => 100,
            'data_saldo_inicial' => '2026-06-01',
        ]);

        $this->criarLancamento($conta, LancamentoTipo::RECEITA, 500, 'Recebimento venda', '2026-06-10');
        $this->criarLancamento($conta, LancamentoTipo::DESPESA, 120, 'Pagamento fornecedor', '2026-06-11');
        $this->criarLancamento($conta, LancamentoTipo::TRANSFERENCIA, 30, 'Transferencia enviada para aplicacao', '2026-06-12');
        $this->criarLancamento($conta, LancamentoTipo::TRANSFERENCIA, 20, 'Transferencia recebida do caixa', '2026-06-13');
        $this->criarLancamento($conta, LancamentoTipo::DESPESA, 999, 'Lancamento cancelado', '2026-06-14', LancamentoStatus::CANCELADO);

        $dados = app(FinanceiroExtratoService::class)->montar([
            'conta_id' => $conta->id,
            'data_inicio' => '2026-06-01',
            'data_fim' => '2026-06-30',
        ]);

        $this->assertSame(100.0, $dados['resumo']['saldo_inicial']);
        $this->assertSame(520.0, $dados['resumo']['receitas_realizadas']);
        $this->assertSame(150.0, $dados['resumo']['despesas_realizadas']);
        $this->assertSame(370.0, $dados['resumo']['total_periodo']);
        $this->assertSame(470.0, $dados['resumo']['saldo_realizado']);
        $this->assertSame(999.0, $dados['resumo']['perdidos']);
        $this->assertSame('saldo_livro', $dados['resumo']['saldo_base_origem']);
    }

    public function test_comando_audita_saldos_em_dry_run_e_aplica_correcao_explicitamente(): void
    {
        $conta = $this->criarConta([
            'nome' => 'Banco do Brasil',
            'saldo_inicial' => 100,
            'saldo_atual' => 2000000,
            'saldo_atual_em' => '2026-06-24 08:00:00',
            'meta_json' => [
                'conta_azul_saldo' => [
                    'saldoAtual' => '200.000,12',
                    'consultado_em' => '2026-06-24 08:00:00',
                ],
            ],
        ]);
        $this->criarLancamento($conta, LancamentoTipo::RECEITA, 50, 'Recebimento venda', '2026-06-10');

        $this->assertSame(0, Artisan::call('conta-azul:auditar-saldos', ['--conta' => $conta->id]));
        $output = Artisan::output();

        $this->assertStringContainsString('Banco do Brasil', $output);
        $this->assertStringContainsString('2.000.000,00', $output);
        $this->assertStringContainsString('200.000,12', $output);
        $this->assertStringContainsString('150,00', $output);

        $this->assertSame(0, Artisan::call('conta-azul:auditar-saldos', [
            '--conta' => $conta->id,
            '--apply' => true,
            '--valor-correto' => '200.000,12',
        ]));

        $conta->refresh();
        $this->assertSame('200000.12', $conta->saldo_atual);
        $this->assertSame(200000.12, $conta->meta_json['saldo_auditoria_manual']['saldo_novo']);
        $this->assertEquals(150.0, $conta->meta_json['saldo_auditoria_manual']['saldo_livro']);
    }

    private function criarConta(array $attrs = []): ContaFinanceira
    {
        return ContaFinanceira::query()->create(array_merge([
            'nome' => 'Conta Teste',
            'slug' => 'conta-teste-' . uniqid(),
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
            'data_saldo_inicial' => '2026-06-01',
            'meta_json' => [],
        ], $attrs));
    }

    private function mapearConta(ContaFinanceira $conta, string $idExterno): void
    {
        ContaAzulMapeamento::query()->create([
            'tipo_entidade' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'id_local' => $conta->id,
            'id_externo' => $idExterno,
            'origem_inicial' => 'teste',
            'sincronizado_em' => now(),
        ]);
    }

    private function insertStagingSaldo(string $idExterno, array $payload): void
    {
        DB::table('stg_conta_azul_saldos_contas_financeiras')->insert([
            'loja_id' => null,
            'identificador_externo' => $idExterno,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'hash_payload' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'status_conciliacao' => 'novo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function criarLancamento(
        ContaFinanceira $conta,
        LancamentoTipo $tipo,
        float $valor,
        string $descricao,
        string $data,
        LancamentoStatus $status = LancamentoStatus::CONFIRMADO
    ): LancamentoFinanceiro {
        return LancamentoFinanceiro::query()->create([
            'descricao' => $descricao,
            'tipo' => $tipo->value,
            'status' => $status->value,
            'conta_id' => $conta->id,
            'valor' => $valor,
            'data_movimento' => $data,
            'data_pagamento' => $data,
            'competencia' => $data,
        ]);
    }
}
