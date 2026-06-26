<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Mappers\ContaAzulContaPagarMapper;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaPagar;
use Tests\TestCase;

class ContaAzulContaPagarMapperTest extends TestCase
{
    /** @var array<int, int> */
    private array $categoriaIds = [];

    /** @var array<int, int> */
    private array $centroCustoIds = [];

    protected function tearDown(): void
    {
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

        $conta = new ContaPagar([
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

        $payload = (new ContaAzulContaPagarMapper())->fromLocal($conta);

        $this->assertSame('2026-06-25', $payload['competenceDate']);
        $this->assertSame('categoria-ext-1', $payload['rateio'][0]['id_categoria']);
        $this->assertEqualsWithDelta(50.0, $payload['rateio'][0]['valor'], 0.001);
        $this->assertSame('centro-ext-1', $payload['rateio'][0]['rateio_centro_custo'][0]['id_centro_custo']);
        $this->assertSame('PIX_PAGAMENTO_INSTANTANEO', $payload['condicao_pagamento']['tipo_pagamento']);
        $this->assertSame('2026-06-30', $payload['condicao_pagamento']['parcelas'][0]['data_vencimento']);
        $this->assertEqualsWithDelta(50.0, $payload['condicao_pagamento']['parcelas'][0]['valor'], 0.001);
        $this->assertSame('PIX_PAGAMENTO_INSTANTANEO', $payload['condicao_pagamento']['parcelas'][0]['metodo_pagamento']);
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
}
