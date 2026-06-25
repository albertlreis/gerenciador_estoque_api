<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\DespesaRecorrente;
use App\Models\DespesaRecorrenteExecucao;
use App\Models\Fornecedor;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroRecorrenciasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['conta_azul.flags.exportacao_ativa' => false]);

        $usuario = Usuario::create([
            'nome' => 'Usuario Financeiro Recorrencias',
            'email' => 'financeiro-recorrencias-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_criacao_recorrente_pagar_gera_contas_futuras_vinculadas(): void
    {
        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor Recorrente',
            'status' => 1,
        ]);

        $response = $this->postJson('/api/v1/financeiro/contas-pagar', [
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Aluguel recorrente',
            'data_emissao' => '2026-06-24',
            'data_vencimento' => '2026-07-10',
            'valor_bruto' => 1200,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'forma_pagamento' => 'PIX',
            'recorrencia' => [
                'frequencia' => 'MENSAL',
                'intervalo' => 1,
                'termino_tipo' => 'OCORRENCIAS',
                'ocorrencias' => 3,
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.origem', 'recorrente');

        $serie = DespesaRecorrente::query()
            ->where('direcao', 'PAGAR')
            ->where('descricao', 'Aluguel recorrente')
            ->firstOrFail();
        $this->assertSame('PAGAR', $serie->direcao);
        $this->assertSame(3, (int) $serie->ocorrencias_total);

        $this->assertSame(
            ['2026-07-10', '2026-08-10', '2026-09-10'],
            ContaPagar::query()
                ->where('despesa_recorrente_id', $serie->id)
                ->orderBy('data_vencimento')
                ->pluck('data_vencimento')
                ->map(fn ($date) => $date->format('Y-m-d'))
                ->all()
        );
        $this->assertSame(3, DespesaRecorrenteExecucao::query()->where('despesa_recorrente_id', $serie->id)->count());

        $this->getJson('/api/v1/financeiro/contas-pagar?origem=recorrente')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_criacao_recorrente_receber_gera_contas_futuras_vinculadas(): void
    {
        $cliente = Cliente::create([
            'nome' => 'Cliente Recorrente',
            'tipo' => 'pf',
        ]);

        $response = $this->postJson('/api/v1/financeiro/contas-receber', [
            'cliente_id' => $cliente->id,
            'descricao' => 'Assinatura recorrente',
            'data_emissao' => '2026-06-24',
            'data_vencimento' => '2026-06-24',
            'valor_bruto' => 250,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'forma_recebimento' => 'PIX',
            'recorrencia' => [
                'frequencia' => 'SEMANAL',
                'intervalo' => 1,
                'termino_tipo' => 'OCORRENCIAS',
                'ocorrencias' => 2,
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.origem', 'recorrente');

        $serie = DespesaRecorrente::query()
            ->where('direcao', 'RECEBER')
            ->where('descricao', 'Assinatura recorrente')
            ->firstOrFail();
        $this->assertSame('RECEBER', $serie->direcao);
        $this->assertSame($cliente->id, (int) $serie->cliente_id);

        $this->assertSame(
            ['2026-06-24', '2026-07-01'],
            ContaReceber::query()
                ->where('despesa_recorrente_id', $serie->id)
                ->orderBy('data_vencimento')
                ->pluck('data_vencimento')
                ->map(fn ($date) => $date->format('Y-m-d'))
                ->all()
        );
        $this->assertSame(2, DespesaRecorrenteExecucao::query()->where('despesa_recorrente_id', $serie->id)->whereNotNull('conta_receber_id')->count());

        $this->getJson('/api/v1/financeiro/contas-receber?origem=recorrente')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_rejeita_recorrencia_com_parcelamento_no_mesmo_lancamento(): void
    {
        $response = $this->postJson('/api/v1/financeiro/contas-pagar', [
            'descricao' => 'Despesa invalida',
            'data_emissao' => '2026-06-24',
            'data_vencimento' => '2026-07-10',
            'valor_bruto' => 500,
            'recorrencia' => [
                'frequencia' => 'MENSAL',
                'intervalo' => 1,
                'termino_tipo' => 'OCORRENCIAS',
                'ocorrencias' => 2,
            ],
            'parcelamento' => [
                'quantidade_parcelas' => 2,
                'valor_entrada' => 0,
                'intervalo_meses' => 1,
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recorrencia');

        $this->assertSame(0, ContaPagar::query()->where('descricao', 'Despesa invalida')->count());
        $this->assertSame(0, DespesaRecorrente::query()->where('descricao', 'Despesa invalida')->count());
    }
}
