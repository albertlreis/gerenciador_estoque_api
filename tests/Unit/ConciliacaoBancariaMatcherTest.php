<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\Fornecedor;
use App\Services\ConciliacaoBancaria\ConciliacaoBancariaMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConciliacaoBancariaMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ConciliacaoBancariaMatcher $matcher;
    private ContaFinanceira $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = app(ConciliacaoBancariaMatcher::class);
        $this->conta = ContaFinanceira::create([
            'nome' => 'Banco do Brasil',
            'tipo' => 'banco',
            'banco_codigo' => '001',
            'conta' => '106263',
            'conta_dv' => '8',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
        ]);
    }

    public function test_match_exato_seguro_continua_sugerido(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'KASA TUA DECORACOES', 'status' => 1]);
        ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Compra KASA TUA DECORACOES',
            'data_vencimento' => '2026-06-15',
            'valor_bruto' => 17104.50,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
        ]);

        $match = $this->matcher->match([
            'data_movimento' => '2026-06-15',
            'valor' => -17104.50,
            'memo' => 'PIX - ENVIADO - 15/06 16:17 KASA TUA DECORACOES',
        ], $this->conta->id);

        $this->assertSame('sugerido', $match['status']);
        $this->assertSame('conta_pagar', $match['candidato']['tipo']);
        $this->assertSame(95, $match['candidato']['score']);
    }

    public function test_identifica_fornecedor_provavel_por_texto_e_recorrencia(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'AGUAS DO PARA A SPE C.A GE PARA', 'status' => 1]);
        ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'PGTO CONTA AGUA AGUAS DO PARA A',
            'data_vencimento' => '2026-04-24',
            'valor_bruto' => 146.60,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PAGA',
        ]);

        $match = $this->matcher->match([
            'data_movimento' => '2026-06-15',
            'valor' => -146.60,
            'memo' => 'PGTO CONTA AGUA - AGUAS DO PARA A',
        ], $this->conta->id);

        $this->assertSame('pendente', $match['status']);
        $this->assertSame('fornecedor_provavel', $match['candidatos'][0]['tipo']);
        $this->assertSame($fornecedor->id, $match['candidatos'][0]['id']);
        $this->assertFalse($match['candidatos'][0]['confirmavel']);
        $this->assertGreaterThanOrEqual(80, $match['score']);
    }

    public function test_identifica_tarifa_bancaria_sem_candidato_confirmavel(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'BANCO DO BRASIL', 'status' => 1]);

        $match = $this->matcher->match([
            'data_movimento' => '2026-06-15',
            'valor' => -4.38,
            'memo' => 'CBR LIQUIDACAO - TAR. AGRUPADAS - OCORRENCIA 12/06/2026',
        ], $this->conta->id);

        $this->assertSame('pendente', $match['status']);
        $this->assertSame('tarifa_bancaria_provavel', $match['candidatos'][0]['tipo']);
        $this->assertSame($fornecedor->id, $match['candidatos'][0]['id']);
        $this->assertFalse($match['candidatos'][0]['confirmavel']);
    }

    public function test_mantem_pendente_quando_nao_ha_entidade_provavel(): void
    {
        Cliente::create(['nome' => 'CLIENTE SEM RELACAO', 'tipo' => 'pj']);
        ContaReceber::create([
            'descricao' => 'Recebimento sem relacao',
            'data_vencimento' => '2026-06-15',
            'valor_bruto' => 999.99,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'valor_liquido' => 999.99,
            'valor_recebido' => 0,
            'saldo_aberto' => 999.99,
            'status' => 'ABERTA',
        ]);

        $match = $this->matcher->match([
            'data_movimento' => '2026-06-15',
            'valor' => 14150.71,
            'memo' => 'COBRANCA CLIENTE SIERRA',
        ], $this->conta->id);

        $this->assertSame('pendente', $match['status']);
        $this->assertArrayNotHasKey('candidatos', $match);
    }
}
