<?php

namespace Tests\Feature;

use App\Services\Import\ProdutoUpsertService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProdutoUpsertAtributosRepetidosTest extends TestCase
{
    public function test_upsert_preserva_pares_repetidos_e_usa_conjunto_completo_na_identidade(): void
    {
        $produtoId = $this->criarProduto();
        $service = app(ProdutoUpsertService::class);
        $payload = $this->payload($produtoId, [
            ['atributo' => 'Madeira', 'valor' => 'AC03'],
            ['atributo' => 'madeira', 'valor' => 'MT31-PRETO'],
        ]);

        $primeiro = $service->upsertProdutoVariacao($payload);
        $primeiraVariacaoId = (int) $primeiro['variacao']->id;

        $this->assertSame(2, DB::table('produto_variacao_atributos')
            ->where('id_variacao', $primeiraVariacaoId)
            ->where('atributo', 'madeira')
            ->count());

        $mesmoConjuntoOutraOrdem = $service->upsertProdutoVariacao($this->payload($produtoId, [
            ['atributo' => 'madeira', 'valor' => 'MT31_PRETO'],
            ['atributo' => 'MADEIRA', 'valor' => 'ac03'],
        ]));

        $this->assertSame($primeiraVariacaoId, (int) $mesmoConjuntoOutraOrdem['variacao']->id);

        $mesmoConjuntoComEspaco = $service->upsertProdutoVariacao($this->payload($produtoId, [
            ['atributo' => 'madeira', 'valor' => 'MT31 PRETO'],
            ['atributo' => 'MADEIRA', 'valor' => 'ac03'],
        ]));

        $this->assertSame($primeiraVariacaoId, (int) $mesmoConjuntoComEspaco['variacao']->id);

        $conjuntoIncompleto = $service->upsertProdutoVariacao($this->payload($produtoId, [
            ['atributo' => 'madeira', 'valor' => 'AC03'],
        ]));

        $this->assertNotSame($primeiraVariacaoId, (int) $conjuntoIncompleto['variacao']->id);
        $this->assertSame(2, DB::table('produto_variacoes')->where('produto_id', $produtoId)->count());
    }

    public function test_upsert_mantem_compatibilidade_com_mapa_escalar_legado(): void
    {
        $produtoId = $this->criarProduto();

        $resultado = app(ProdutoUpsertService::class)->upsertProdutoVariacao([
            ...$this->payloadBase($produtoId),
            'atributos' => [
                'madeira' => 'AC03',
                'tecido_1' => 'I-24805',
            ],
        ]);

        $variacaoId = (int) $resultado['variacao']->id;
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'tecido_1',
            'valor' => 'I-24805',
        ]);
    }

    private function payload(int $produtoId, array $atributos): array
    {
        return [
            ...$this->payloadBase($produtoId),
            // O mapa continua presente durante a versão de compatibilidade,
            // mas a lista é a fonte preferencial e preserva nomes repetidos.
            'atributos' => ['madeira' => 'MT31-PRETO'],
            'atributos_lista' => $atributos,
        ];
    }

    private function payloadBase(int $produtoId): array
    {
        return [
            'produto_id_forcado' => $produtoId,
            'nome_limpo' => 'Poltrona Repetida',
            'nome_completo' => 'Poltrona Repetida',
            'referencia' => 'REF-UP-ATTR',
            'fonte' => 'importacao_normalizada',
            'valor' => 100,
            'custo' => 50,
        ];
    }

    private function criarProduto(): int
    {
        $agora = now();
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria upsert '.uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        return DB::table('produtos')->insertGetId([
            'nome' => 'Poltrona Repetida',
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
    }
}
