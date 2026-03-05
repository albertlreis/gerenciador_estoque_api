<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacoesSeeder extends Seeder
{
    /**
     * @throws \Exception
     */
    public function run(): void
    {
        $now = Carbon::now();
        $produtos = DB::table('produtos')->get();

        if ($produtos->isEmpty()) {
            throw new Exception('Nenhum produto encontrado.');
        }

        foreach ($produtos as $produto) {
            for ($i = 1; $i <= 2; $i++) {
                $referencia = sprintf('PV-%04d-%02d', $produto->id, $i);
                $preco = 1000 + ($produto->id * 10) + ($i * 100);
                $custo = $preco - 250;

                DB::table('produto_variacoes')->updateOrInsert(
                    ['referencia' => $referencia],
                    [
                        'produto_id' => $produto->id,
                        'nome' => 'Variação ' . $i,
                        'preco' => $preco,
                        'custo' => $custo,
                        'codigo_barras' => sprintf('789%09d', ($produto->id * 10) + $i),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                $variacaoId = (int) DB::table('produto_variacoes')
                    ->where('referencia', $referencia)
                    ->value('id');

                // Atributos com base na categoria
                $atributos = $this->atributosPorCategoria($produto->id_categoria);

                foreach ($atributos as $atributo => $valores) {
                    $valor = $valores[($produto->id + $i) % count($valores)];
                    DB::table('produto_variacao_atributos')->updateOrInsert(
                        [
                            'id_variacao' => $variacaoId,
                            'atributo' => $atributo,
                        ],
                        [
                            'valor' => $valor,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }
    }

    private function atributosPorCategoria($categoriaId): array
    {
        $mapa = [
            'Sofá Retrátil' => [
                'tecido' => ['veludo', 'linho', 'suede'],
                'cor' => ['cinza', 'bege', 'azul-marinho'],
                'estrutura' => ['madeira', 'aço'],
            ],
            'Sofá de Canto Modular' => [
                'tecido' => ['bouclé', 'linho', 'veludo'],
                'cor' => ['areia', 'grafite', 'verde-oliva'],
            ],
            'Mesa Redonda' => [
                'tipo_madeira' => ['carvalho', 'imbuia', 'nogueira'],
                'acabamento' => ['fosco', 'brilhante'],
                'formato' => ['redonda'],
            ],
            'Mesa de Escritório' => [
                'material' => ['mdf', 'mdp', 'vidro'],
                'cor' => ['branco', 'preto', 'cinza'],
            ],
            'Cadeira Gamer' => [
                'revestimento' => ['couro ecológico', 'tecido'],
                'estrutura' => ['alumínio', 'ferro'],
                'cor' => ['preto/vermelho', 'azul/preto'],
            ],
            'Cama de Casal' => [
                'tamanho' => ['casal', 'queen', 'king'],
                'material' => ['mdf', 'madeira maciça'],
            ],
            'Poltrona' => [
                'tecido' => ['veludo', 'linho', 'sintético'],
                'cor' => ['vermelho', 'cinza', 'mostarda'],
            ],
            'Beliche' => [
                'material' => ['madeira', 'aço'],
                'cor' => ['branco', 'carvalho', 'tabaco'],
            ],
            'Nichos Decorativos' => [
                'formato' => ['hexagonal', 'quadrado', 'circular'],
                'cor' => ['branco', 'amadeirado', 'preto fosco'],
            ],
            'Aparador' => [
                'material' => ['mdf', 'vidro', 'madeira'],
                'cor' => ['nude', 'nogueira', 'preto'],
            ],
        ];

        $categoria = DB::table('categorias')->where('id', $categoriaId)->value('nome');

        return $mapa[$categoria] ?? [
            'material' => ['mdf', 'aço'],
            'cor' => ['branco', 'preto'],
        ];
    }
}
