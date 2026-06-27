<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\LocalizacaoEstoque;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstoqueMovimentacaoIndicadoresTest extends TestCase
{
    use RefreshDatabase;

    private function criarCenarioBase(): array
    {
        $suffix = uniqid('', true);

        $usuario = Usuario::create([
            'nome' => 'Usuario Estoque',
            'email' => 'usuario_estoque_indicadores_' . $suffix . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Estoque']);

        $produto = Produto::create([
            'nome' => 'Produto Estoque Indicadores',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'EST-IND-1',
            'nome' => 'Variacao Indicadores',
            'preco' => 120,
            'custo' => 55,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Indicadores']);

        Estoque::updateOrCreate(
            [
                'id_variacao' => $variacao->id,
                'id_deposito' => $deposito->id,
            ],
            [
                'quantidade' => 0,
            ]
        );

        return [$usuario, $variacao, $deposito];
    }

    public function test_atualiza_data_entrada_e_ultima_venda_conforme_movimentacoes(): void
    {
        [$usuario, $variacao, $deposito] = $this->criarCenarioBase();

        $service = app(EstoqueMovimentacaoService::class);

        $service->registrarMovimentacaoManual([
            'id_variacao' => $variacao->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            'quantidade' => 5,
            'data_movimentacao' => '2026-02-10 09:00:00',
        ], $usuario->id);

        $estoque = Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->firstOrFail();

        $this->assertSame(5, (int) $estoque->quantidade);
        $this->assertSame('2026-02-10', optional($estoque->data_entrada_estoque_atual)->toDateString());
        $this->assertNull($estoque->ultima_venda_em);

        $service->registrarMovimentacaoManual([
            'id_variacao' => $variacao->id,
            'id_deposito_origem' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            'quantidade' => 2,
            'data_movimentacao' => '2026-02-12 14:00:00',
        ], $usuario->id);

        $estoque->refresh();

        $this->assertSame(3, (int) $estoque->quantidade);
        $this->assertSame('2026-02-10', optional($estoque->data_entrada_estoque_atual)->toDateString());
        $this->assertSame('2026-02-12', optional($estoque->ultima_venda_em)->toDateString());

        $service->registrarMovimentacaoManual([
            'id_variacao' => $variacao->id,
            'id_deposito_origem' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            'quantidade' => 3,
            'data_movimentacao' => '2026-02-15 10:00:00',
        ], $usuario->id);

        $estoque->refresh();

        $this->assertSame(0, (int) $estoque->quantidade);
        $this->assertNull($estoque->data_entrada_estoque_atual);
        $this->assertSame('2026-02-15', optional($estoque->ultima_venda_em)->toDateString());
    }

    public function test_endpoint_estoque_atual_retorna_campos_de_indicadores(): void
    {
        [$usuario, $variacao, $deposito] = $this->criarCenarioBase();
        Sanctum::actingAs($usuario);

        Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->update([
                'quantidade' => 4,
                'data_entrada_estoque_atual' => '2026-02-01 08:00:00',
                'ultima_venda_em' => '2026-02-05 11:30:00',
            ]);

        $response = $this->getJson('/api/v1/estoque/atual?deposito=' . $deposito->id);
        $response->assertOk();

        $linha = collect($response->json('data'))->firstWhere('variacao_id', $variacao->id);

        $this->assertNotNull($linha);
        $this->assertSame(55.0, (float) ($linha['custo_unitario'] ?? 0));
        $this->assertSame('2026-02-01', $linha['data_entrada_estoque_atual']);
        $this->assertSame('2026-02-05', $linha['ultima_venda_em']);
        $this->assertArrayHasKey('dias_sem_venda', $linha);
    }

    public function test_filtro_de_movimentacoes_aceita_busca_com_barra_na_referencia(): void
    {
        [$usuario, $variacao, $deposito] = $this->criarCenarioBase();
        Sanctum::actingAs($usuario);

        $variacao->update(['referencia' => 'VENDA/001']);

        $service = app(EstoqueMovimentacaoService::class);
        $service->registrarMovimentacaoManual([
            'id_variacao' => $variacao->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA->value,
            'quantidade' => 2,
            'data_movimentacao' => '2026-02-10 08:00:00',
        ], $usuario->id);

        $response = $this->getJson('/api/v1/estoque/movimentacoes?produto=VENDA%2F001');
        $response->assertOk();

        $referencias = collect($response->json('data'))->pluck('produto_referencia')->filter()->all();
        $this->assertContains('VENDA/001', $referencias);
    }

    public function test_filtra_movimentacoes_por_localizacao_do_estoque(): void
    {
        $suffix = uniqid('', true);

        $usuario = Usuario::create([
            'nome' => 'Usuario Localizacao',
            'email' => 'usuario_localizacao_movimentacoes_' . $suffix . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        Sanctum::actingAs($usuario);

        $categoria = Categoria::create(['nome' => 'Categoria Localizacao']);
        $deposito = Deposito::create(['nome' => 'Deposito Localizacao']);

        $produtoAlvo = Produto::create([
            'nome' => 'Produto Localizacao Alvo',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $produtoOutro = Produto::create([
            'nome' => 'Produto Localizacao Outro',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoAlvo = ProdutoVariacao::create([
            'produto_id' => $produtoAlvo->id,
            'referencia' => 'LOC-ALVO',
            'nome' => 'Variacao Localizacao Alvo',
            'preco' => 120,
            'custo' => 55,
        ]);
        $variacaoOutra = ProdutoVariacao::create([
            'produto_id' => $produtoOutro->id,
            'referencia' => 'LOC-OUTRA',
            'nome' => 'Variacao Localizacao Outra',
            'preco' => 120,
            'custo' => 55,
        ]);

        $estoqueAlvo = Estoque::updateOrCreate(
            ['id_variacao' => $variacaoAlvo->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0]
        );
        $estoqueOutro = Estoque::updateOrCreate(
            ['id_variacao' => $variacaoOutra->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0]
        );

        $localizacaoAlvo = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => 'Showroom Norte',
            'corredor' => 'Corredor Especial',
            'setor' => 'A',
            'coluna' => '01',
            'codigo_composto' => 'Showroom Norte-Corredor Especial-A-01',
            'observacoes' => 'Perto da entrada',
            'ativo' => true,
        ]);
        $localizacaoOutra = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => 'Estoque Sul',
            'corredor' => 'Corredor Comum',
            'setor' => 'B',
            'coluna' => '09',
            'codigo_composto' => 'Estoque Sul-Corredor Comum-B-09',
            'ativo' => true,
        ]);

        $estoqueAlvo->update(['localizacao_id' => $localizacaoAlvo->id]);
        $estoqueOutro->update(['localizacao_id' => $localizacaoOutra->id]);

        $service = app(EstoqueMovimentacaoService::class);
        $service->registrarMovimentacaoManual([
            'id_variacao' => $variacaoAlvo->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA->value,
            'quantidade' => 2,
            'data_movimentacao' => '2026-02-10 08:00:00',
        ], $usuario->id);
        $service->registrarMovimentacaoManual([
            'id_variacao' => $variacaoOutra->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA->value,
            'quantidade' => 2,
            'data_movimentacao' => '2026-02-11 08:00:00',
        ], $usuario->id);

        $response = $this->getJson('/api/v1/estoque/movimentacoes?localizacao=' . urlencode('Corredor Especial'));
        $response->assertOk();

        $referencias = collect($response->json('data'))->pluck('produto_referencia')->all();
        $this->assertContains('LOC-ALVO', $referencias);
        $this->assertNotContains('LOC-OUTRA', $referencias);

        $responsePorId = $this->getJson('/api/v1/estoque/movimentacoes?localizacao_id=' . $localizacaoAlvo->id);
        $responsePorId->assertOk();

        $referenciasPorId = collect($responsePorId->json('data'))->pluck('produto_referencia')->all();
        $this->assertContains('LOC-ALVO', $referenciasPorId);
        $this->assertNotContains('LOC-OUTRA', $referenciasPorId);
    }
}
