<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            $quantidadeVariacoes = rand(1, 3);

            for ($i = 1; $i <= $quantidadeVariacoes; $i++) {
                $preco = rand(800, 3000);
                $custo = rand(500, $preco - 100);

                $variacaoId = DB::table('produto_variacoes')->insertGetId([
                    'produto_id' => $produto->id,
                    'referencia' => strtoupper(Str::random(6)) . '-' . $produto->id . $i,
                    'nome' => 'Variação ' . $i,
                    'preco' => $preco,
                    'custo' => $custo,
                    'codigo_barras' => '789' . rand(100000000, 999999999),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Atributos com base na categoria
                $atributos = $this->atributosPorCategoria($produto->id_categoria);

                foreach ($atributos as $atributo => $valores) {
                    DB::table('produto_variacao_atributos')->insert([
                        'id_variacao' => $variacaoId,
                        'atributo' => $atributo,
                        'valor' => $valores[array_rand($valores)],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
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
