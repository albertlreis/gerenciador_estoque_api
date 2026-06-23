<?php

namespace Tests\Unit;

use App\Services\ConciliacaoBancaria\OfxParser;
use PHPUnit\Framework\TestCase;

class ConciliacaoBancariaOfxParserTest extends TestCase
{
    public function test_parser_lida_com_ofx_sgml_do_banco_do_brasil(): void
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/banco-brasil-conciliacao.ofx');
        $parsed = (new OfxParser())->parse((string) $raw);

        $this->assertSame('001', $parsed['banco_codigo']);
        $this->assertSame('Banco do Brasil S/A', $parsed['banco_nome']);
        $this->assertSame('106263', $parsed['conta']);
        $this->assertSame('8', $parsed['conta_dv']);
        $this->assertSame('BRL', $parsed['moeda']);
        $this->assertSame('2026-06-12', $parsed['data_inicio']);
        $this->assertSame('2026-06-15', $parsed['data_fim']);
        $this->assertSame(76074.52, $parsed['saldo_final']);
        $this->assertSame('2026-06-16 00:00:00', $parsed['saldo_final_em']);
        $this->assertCount(2, $parsed['transacoes']);
        $this->assertSame(-17104.50, $parsed['transacoes'][1]['valor']);
        $this->assertSame('PIX - ENVIADO - 15/06 16:17 KASA TUA DECORACOES', $parsed['transacoes'][1]['memo']);
    }
}
