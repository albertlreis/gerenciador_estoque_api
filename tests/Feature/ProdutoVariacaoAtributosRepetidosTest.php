<?php

namespace Tests\Feature;

use App\Models\ProdutoVariacao;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProdutoVariacaoAtributosRepetidosTest extends TestCase
{
    public function test_indice_permite_nome_repetido_e_bloqueia_triplo_identico(): void
    {
        $variacaoId = $this->criarVariacao();
        $agora = now();

        DB::table('produto_variacao_atributos')->insert([
            [
                'id_variacao' => $variacaoId,
                'atributo' => 'madeira',
                'valor' => 'AC03',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            [
                'id_variacao' => $variacaoId,
                'atributo' => 'madeira',
                'valor' => 'MT31-PRETO',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
        ]);

        $this->assertSame(2, DB::table('produto_variacao_atributos')
            ->where('id_variacao', $variacaoId)
            ->where('atributo', 'madeira')
            ->count());
        $this->assertSame(
            ['madeira:AC03', 'madeira:MT31-PRETO'],
            ProdutoVariacao::findOrFail($variacaoId)->atributos
                ->map(fn ($atributo) => $atributo->atributo.':'.$atributo->valor)
                ->all()
        );

        try {
            DB::table('produto_variacao_atributos')->insert([
                'id_variacao' => $variacaoId,
                'atributo' => 'madeira',
                'valor' => 'AC03',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
            $this->fail('O banco deveria rejeitar o triplo idêntico.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->getCode());
        }
    }

    public function test_rollback_aborta_sem_alterar_schema_quando_ha_nomes_repetidos(): void
    {
        $variacaoId = $this->criarVariacao();
        $agora = now();

        DB::table('produto_variacao_atributos')->insert([
            [
                'id_variacao' => $variacaoId,
                'atributo' => 'Tecido 1',
                'valor' => 'I-24805',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            [
                'id_variacao' => $variacaoId,
                'atributo' => 'tecido-1',
                'valor' => 'L-18042',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_07_13_090000_allow_repeated_names_in_produto_variacao_atributos.php'
        );

        try {
            $migration->down();
            $this->fail('O rollback deveria abortar antes de remover os índices novos.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback abortado', $exception->getMessage());
        }

        $indices = collect(DB::select('SHOW INDEX FROM produto_variacao_atributos'));
        $this->assertTrue($indices->contains(
            fn ($indice) => $indice->Key_name === 'pva_variacao_atributo_valor_unique'
                && (int) $indice->Non_unique === 0
        ));
    }

    private function criarVariacao(): int
    {
        $agora = now();
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria atributo repetido '.uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => 'Produto atributo repetido '.uniqid(),
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => null,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        return DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-ATTR-'.uniqid(),
            'nome' => 'Variação atributo repetido',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
    }
}
