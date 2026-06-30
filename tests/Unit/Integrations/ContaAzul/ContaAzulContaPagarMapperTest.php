<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Mappers\ContaAzulContaPagarMapper;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\Fornecedor;
use Tests\TestCase;

class ContaAzulContaPagarMapperTest extends TestCase
{
    /** @var array<int, int> */
    private array $mapeamentoIds = [];

    /** @var array<int, int> */
    private array $categoriaIds = [];

    /** @var array<int, int> */
    private array $centroCustoIds = [];

    /** @var array<int, int> */
    private array $contaFinanceiraIds = [];

    /** @var array<int, int> */
    private array $fornecedorIds = [];

    protected function tearDown(): void
    {
        if ($this->mapeamentoIds !== []) {
            ContaAzulMapeamento::query()->whereIn('id', $this->mapeamentoIds)->delete();
        }

        if ($this->fornecedorIds !== []) {
            Fornecedor::withTrashed()->whereIn('id', $this->fornecedorIds)->forceDelete();
        }

        if ($this->contaFinanceiraIds !== []) {
            ContaFinanceira::query()->whereIn('id', $this->contaFinanceiraIds)->delete();
        }

        if ($this->centroCustoIds !== []) {
            CentroCusto::query()->whereIn('id', $this->centroCustoIds)->delete();
        }

        if ($this->categoriaIds !== []) {
            CategoriaFinanceira::query()->whereIn('id', $this->categoriaIds)->delete();
        }

        parent::tearDown();
    }

    public function test_from_local_monta_payload_de_criacao_conta_pagar(): void
    {
        $categoria = CategoriaFinanceira::create([
            'nome' => 'Categoria Conta Azul Mapper ' . uniqid(),
            'slug' => 'cat-ca-mapper-' . uniqid(),
            'tipo' => 'despesa',
            'ativo' => true,
            'meta_json' => ['conta_azul' => ['id' => 'categoria-ext-1']],
        ]);
        $this->categoriaIds[] = (int) $categoria->id;

        $centroCusto = CentroCusto::create([
            'nome' => 'Centro Conta Azul Mapper ' . uniqid(),
            'slug' => 'cc-ca-mapper-' . uniqid(),
            'ativo' => true,
            'meta_json' => ['conta_azul_id' => 'centro-ext-1'],
        ]);
        $this->centroCustoIds[] = (int) $centroCusto->id;

        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor Conta Azul Mapper ' . uniqid(),
            'status' => 1,
        ]);
        $this->fornecedorIds[] = (int) $fornecedor->id;
        $this->mapearEntidade(ContaAzulEntityType::FORNECEDOR, (int) $fornecedor->id, 'fornecedor-ext-1');

        $contaFinanceira = ContaFinanceira::create([
            'nome' => 'Conta Financeira Conta Azul Mapper ' . uniqid(),
            'slug' => 'conta-ca-mapper-' . uniqid(),
            'tipo' => 'banco',
            'ativo' => true,
        ]);
        $this->contaFinanceiraIds[] = (int) $contaFinanceira->id;
        $this->mapearEntidade(ContaAzulEntityType::CONTA_FINANCEIRA, (int) $contaFinanceira->id, 'conta-financeira-ext-1');

        $conta = new ContaPagar([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Combustivel Roberta',
            'numero_documento' => 'CP-1',
            'data_emissao' => '2026-06-25',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => '50.00',
            'desconto' => '0.00',
            'juros' => '0.00',
            'multa' => '0.00',
            'forma_pagamento' => 'pix',
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centroCusto->id,
        ]);
        $conta->setRelation('categoria', $categoria);
        $conta->setRelation('centroCusto', $centroCusto);
        $conta->setRelation('fornecedor', $fornecedor);

        $pagamento = new ContaPagarPagamento([
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $pagamento->setRelation('contaFinanceira', $contaFinanceira);
        $conta->setRelation('pagamentos', collect([$pagamento]));

        $payload = (new ContaAzulContaPagarMapper())->fromLocal($conta);

        $this->assertSame('2026-06-25', $payload['data_competencia']);
        $this->assertSame('Combustivel Roberta', $payload['descricao']);
        $this->assertSame('CP-1 - Combustivel Roberta', $payload['observacao']);
        $this->assertSame('fornecedor-ext-1', $payload['contato']);
        $this->assertSame('conta-financeira-ext-1', $payload['conta_financeira']);
        $this->assertSame('categoria-ext-1', $payload['rateio'][0]['id_categoria']);
        $this->assertEqualsWithDelta(50.0, $payload['rateio'][0]['valor'], 0.001);
        $this->assertSame('centro-ext-1', $payload['rateio'][0]['rateio_centro_custo'][0]['id_centro_custo']);

        $parcela = $payload['condicao_pagamento']['parcelas'][0];
        $this->assertSame('CP-1 - Combustivel Roberta', $parcela['descricao']);
        $this->assertSame('2026-06-30', $parcela['data_vencimento']);
        $this->assertSame('Pagamento via pix', $parcela['nota']);
        $this->assertSame('conta-financeira-ext-1', $parcela['conta_financeira']);
        $this->assertEqualsWithDelta(50.0, $parcela['detalhe_valor']['valor_bruto'], 0.001);
        $this->assertEqualsWithDelta(50.0, $parcela['detalhe_valor']['valor_liquido'], 0.001);
        $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['desconto'], 0.001);
        $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['juros'], 0.001);
        $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['multa'], 0.001);
        $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['taxa'], 0.001);
    }

    public function test_from_local_falha_com_mensagem_clara_sem_categoria_mapeada(): void
    {
        $conta = new ContaPagar([
            'descricao' => 'Conta sem categoria externa',
            'data_emissao' => '2026-06-25',
            'data_vencimento' => '2026-06-25',
            'valor_bruto' => '50.00',
            'desconto' => '0.00',
            'juros' => '0.00',
            'multa' => '0.00',
        ]);

        $this->expectException(ContaAzulException::class);
        $this->expectExceptionMessage('categoria financeira local sem mapeamento externo');

        (new ContaAzulContaPagarMapper())->fromLocal($conta);
    }

    private function mapearEntidade(string $tipoEntidade, int $idLocal, string $idExterno): ContaAzulMapeamento
    {
        $mapeamento = ContaAzulMapeamento::create([
            'tipo_entidade' => $tipoEntidade,
            'id_local' => $idLocal,
            'id_externo' => $idExterno,
            'origem_inicial' => 'test',
            'sincronizado_em' => now(),
        ]);
        $this->mapeamentoIds[] = (int) $mapeamento->id;

        return $mapeamento;
    }
}
