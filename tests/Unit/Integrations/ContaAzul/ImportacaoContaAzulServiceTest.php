<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Import\CategoriaFinanceiraContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\CentroCustoContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\ContaFinanceiraContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\NotaContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\PessoaContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\ProdutoContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\TituloContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\VendaContaAzulImportAdapter;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ImportacaoContaAzulServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_titulos_aceitando_itens_e_items_e_usa_paginacao_explicita(): void
    {
        $config = config('conta_azul');
        $config['pagination']['page_size'] = 2;

        $client = Mockery::mock(\App\Integrations\ContaAzul\Clients\ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->with('v1/financeiro/eventos-financeiros/contas-a-receber/buscar', 'token-valido', Mockery::on(function (array $query) {
                return ($query['pagina'] ?? null) === 1
                    && ($query['tamanho_pagina'] ?? null) === 2
                    && !empty($query['data_vencimento_de'])
                    && !empty($query['data_vencimento_ate']);
            }))
            ->andReturn([
                'status' => 200,
                'json' => [
                    'itens' => [
                        ['id' => 'titulo-1', 'descricao' => 'Título 1'],
                        ['id' => 'titulo-2', 'descricao' => 'Título 2'],
                    ],
                    'paginacao' => ['total_paginas' => 2],
                ],
            ]);

        $client->shouldReceive('get')
            ->once()
            ->with('v1/financeiro/eventos-financeiros/contas-a-receber/buscar', 'token-valido', Mockery::on(function (array $query) {
                return ($query['pagina'] ?? null) === 2
                    && ($query['tamanho_pagina'] ?? null) === 2;
            }))
            ->andReturn([
                'status' => 200,
                'json' => [
                    'items' => [
                        ['id' => 'titulo-3', 'descricao' => 'Título 3'],
                    ],
                    'paginacao' => ['total_paginas' => 2],
                ],
            ]);

        $connections = Mockery::mock(ContaAzulConnectionService::class);
        $connections->shouldReceive('getValidAccessToken')->once()->andReturn('token-valido');

        $service = new ImportacaoContaAzulService(
            $config,
            $connections,
            $client,
            [
                new PessoaContaAzulImportAdapter(),
                new ProdutoContaAzulImportAdapter(),
                new VendaContaAzulImportAdapter(),
                new TituloContaAzulImportAdapter(),
                new NotaContaAzulImportAdapter(),
            ]
        );

        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);

        $resultado = $service->importarParaStaging($conexao, ContaAzulEntityType::TITULO);

        $this->assertSame(3, $resultado['lidos']);
        $this->assertDatabaseCount('stg_conta_azul_financeiro', 3);
        $this->assertSame(
            3,
            DB::table('stg_conta_azul_financeiro')->whereIn('identificador_externo', ['titulo-1', 'titulo-2', 'titulo-3'])->count()
        );
    }

    /**
     * @dataProvider catalogosFinanceirosProvider
     */
    public function test_importa_catalogos_financeiros_usando_items(string $adapterClass, string $tipo, string $path, string $table): void
    {
        $config = config('conta_azul');
        $config['pagination']['page_size'] = 2;

        $client = Mockery::mock(\App\Integrations\ContaAzul\Clients\ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->with(ltrim($path, '/'), 'token-valido', Mockery::on(fn (array $query) => ($query['pagina'] ?? null) === 1 && ($query['tamanho_pagina'] ?? null) === 2))
            ->andReturn([
                'status' => 200,
                'json' => [
                    'items' => [
                        ['id' => 'catalogo-1', 'nome' => 'Catalogo 1'],
                        ['id' => 'catalogo-2', 'nome' => 'Catalogo 2'],
                    ],
                    'paginacao' => ['total_paginas' => 1],
                ],
            ]);

        $connections = Mockery::mock(ContaAzulConnectionService::class);
        $connections->shouldReceive('getValidAccessToken')->once()->andReturn('token-valido');

        $service = new ImportacaoContaAzulService(
            $config,
            $connections,
            $client,
            [new $adapterClass()]
        );

        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);

        $resultado = $service->importarParaStaging($conexao, $tipo);

        $this->assertSame(2, $resultado['lidos']);
        $this->assertDatabaseCount($table, 2);
        $this->assertSame(2, DB::table($table)->whereIn('identificador_externo', ['catalogo-1', 'catalogo-2'])->count());
    }

    public static function catalogosFinanceirosProvider(): array
    {
        return [
            'contas financeiras' => [
                ContaFinanceiraContaAzulImportAdapter::class,
                ContaAzulEntityType::CONTA_FINANCEIRA,
                '/v1/conta-financeira',
                'stg_conta_azul_contas_financeiras',
            ],
            'categorias financeiras' => [
                CategoriaFinanceiraContaAzulImportAdapter::class,
                ContaAzulEntityType::CATEGORIA_FINANCEIRA,
                '/v1/categorias',
                'stg_conta_azul_categorias_financeiras',
            ],
            'centros de custo' => [
                CentroCustoContaAzulImportAdapter::class,
                ContaAzulEntityType::CENTRO_CUSTO,
                '/v1/centro-de-custo',
                'stg_conta_azul_centros_custo',
            ],
        ];
    }
}
