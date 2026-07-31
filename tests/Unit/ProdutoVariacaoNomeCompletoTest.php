<?php

namespace Tests\Unit;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use PHPUnit\Framework\TestCase;

class ProdutoVariacaoNomeCompletoTest extends TestCase
{
    public function test_nao_repete_nome_do_produto_quando_a_variacao_e_equivalente(): void
    {
        $variacao = $this->variacao('  LUMINARIA   SOL  ');

        $this->assertSame('Luminaria Sol', $variacao->nome_completo);
    }

    public function test_mantem_nome_da_variacao_quando_ele_e_diferente_do_produto(): void
    {
        $variacao = $this->variacao('Dourada');

        $this->assertSame('Luminaria Sol - Dourada', $variacao->nome_completo);
    }

    public function test_prioriza_atributos_sem_repetir_o_nome_da_variacao(): void
    {
        $variacao = $this->variacao('Luminaria Sol');
        $variacao->setRelation('atributos', collect([
            (object) ['atributo' => 'cor', 'valor' => 'Dourada'],
        ]));

        $this->assertSame('Luminaria Sol (Cor: Dourada)', $variacao->nome_completo);
    }

    private function variacao(string $nome): ProdutoVariacao
    {
        $variacao = new ProdutoVariacao(['nome' => $nome]);
        $variacao->setRelation('produto', new Produto(['nome' => 'Luminaria Sol']));

        return $variacao;
    }
}
