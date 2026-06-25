<?php

namespace Tests\Unit;

use App\Integrations\Bancos\BancoDoBrasil\BancoDoBrasilStatementNormalizer;
use App\Models\ContaFinanceira;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class BancoDoBrasilStatementNormalizerTest extends TestCase
{
    public function test_normaliza_credito_debito_saldo_identificador_e_hash_estavel(): void
    {
        $conta = new ContaFinanceira([
            'banco_nome' => 'Banco do Brasil S/A',
            'banco_codigo' => '001',
            'agencia' => '1234',
            'conta' => '106263',
            'conta_dv' => '8',
            'moeda' => 'BRL',
        ]);

        $payload = [
            'saldoFinal' => '76.074,52',
            'dataSaldo' => '16/06/2026',
            'lancamentos' => [
                [
                    'numeroSequencialLancamento' => '987654',
                    'dataLancamento' => '15/06/2026',
                    'valorLancamento' => '17.104,50',
                    'indicadorSinalLancamento' => 'D',
                    'textoDescricaoHistorico' => 'PIX ENVIADO',
                    'textoInformacaoComplementar' => 'KASA TUA DECORACOES',
                ],
                [
                    'codigoIdentificador' => 'abc-123',
                    'dataMovimento' => '20260616',
                    'valor' => '250.00',
                    'tipoLancamento' => 'CREDITO',
                    'descricao' => 'PIX RECEBIDO CLIENTE',
                ],
            ],
        ];

        $normalizer = new BancoDoBrasilStatementNormalizer();
        $result = $normalizer->normalize(
            $payload,
            $conta,
            Carbon::parse('2026-06-15'),
            Carbon::parse('2026-06-16')
        );
        $again = $normalizer->normalize(
            $payload,
            $conta,
            Carbon::parse('2026-06-15'),
            Carbon::parse('2026-06-16')
        );

        $this->assertSame('001', $result['banco_codigo']);
        $this->assertSame('106263', $result['conta']);
        $this->assertSame(76074.52, $result['saldo_final']);
        $this->assertSame('2026-06-16 00:00:00', $result['saldo_final_em']);
        $this->assertCount(2, $result['transacoes']);
        $this->assertSame(-17104.50, $result['transacoes'][0]['valor']);
        $this->assertSame('DEBIT', $result['transacoes'][0]['tipo_ofx']);
        $this->assertSame('PIX ENVIADO - KASA TUA DECORACOES', $result['transacoes'][0]['memo']);
        $this->assertSame('bb:987654', $result['transacoes'][0]['identificador']);
        $this->assertSame('987654', $result['transacoes'][0]['origem_transacao_id']);
        $this->assertSame($result['transacoes'][0]['hash_unico'], $again['transacoes'][0]['hash_unico']);
        $this->assertSame(250.00, $result['transacoes'][1]['valor']);
        $this->assertSame('CREDIT', $result['transacoes'][1]['tipo_ofx']);
    }
}
