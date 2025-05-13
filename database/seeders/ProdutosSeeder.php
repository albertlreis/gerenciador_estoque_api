<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ProdutosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Categorias e fornecedores
        $categorias = DB::table('categorias')->pluck('id', 'nome');
        if ($categorias->isEmpty()) throw new Exception('Nenhuma categoria encontrada.');

        $fornecedores = DB::table('fornecedores')->pluck('id')->toArray();
        if (empty($fornecedores)) throw new Exception('Nenhum fornecedor encontrado.');

        // Catálogo base
        $catalogoBase = [
            "Sofá Retrátil 2 Lugares" => [
                ["tecido" => "veludo", "cor" => "preto"],
                ["tecido" => "linho", "cor" => "bege"]
            ],
            "Sofá Modular em L" => [
                ["tecido" => "linho", "cor" => "cinza"],
                ["tecido" => "veludo", "cor" => "azul"]
            ],
            "Mesa Redonda de Madeira" => [
                ["tipo_madeira" => "nogueira", "formato" => "redonda"],
                ["tipo_madeira" => "carvalho", "formato" => "redonda"]
            ],
            "Mesa Escritório Compacta" => [
                ["tipo_madeira" => "carvalho", "cor" => "branco"],
                ["tipo_madeira" => "nogueira", "cor" => "preto"]
            ],
            "Cadeira Gamer Reclinável" => [
                ["revestimento" => "couro", "estrutura" => "alumínio"],
                ["revestimento" => "tecido", "estrutura" => "ferro"]
            ],
            "Cama Casal com Cabeceira" => [
                ["tamanho" => "casal", "material" => "mdf"],
                ["tamanho" => "casal", "material" => "madeira"]
            ]
        ];

        // Mapeamento nome do produto → nome da categoria
        $categoriaProduto = [
            "Sofá Retrátil 2 Lugares" => "Sofá Retrátil",
            "Sofá Modular em L" => "Sofá de Canto Modular",
            "Mesa Redonda de Madeira" => "Mesa Redonda",
            "Mesa Escritório Compacta" => "Mesa de Escritório",
            "Cadeira Gamer Reclinável" => "Cadeira Gamer",
            "Cama Casal com Cabeceira" => "Cama de Casal",
        ];

        // Preparar 50 produtos variando o nome
        $produtosGerados = 0;
        $baseNomes = array_keys($catalogoBase);

        while ($produtosGerados < 50) {
            $baseIndex = $produtosGerados % count($baseNomes);
            $nomeBase = $baseNomes[$baseIndex];
            $variacoes = $catalogoBase[$nomeBase];
            $nomeProduto = $nomeBase . ' - Modelo ' . ($produtosGerados + 1);
            $categoriaNome = $categoriaProduto[$nomeBase] ?? null;
            $categoriaId = $categorias[$categoriaNome] ?? null;

            if (!$categoriaId) {
                $produtosGerados++;
                continue;
            }

            $isOutlet = $produtosGerados > 40;
            $dias = $isOutlet ? rand(200, 500) : rand(5, 60);
            $dataUltimaSaida = $now->copy()->subDays($dias)->toDateString();

            $produtoId = DB::table('produtos')->insertGetId([
                'nome' => $nomeProduto,
                'descricao' => "O produto {$nomeProduto} combina funcionalidade e design elegante para sua casa.",
                'id_categoria' => $categoriaId,
                'id_fornecedor' => $fornecedores[array_rand($fornecedores)],
                'altura' => rand(50, 200),
                'largura' => rand(80, 220),
                'profundidade' => rand(50, 150),
                'peso' => rand(10, 100),
                'ativo' => true,
                'is_outlet' => $isOutlet,
                'data_ultima_saida' => $dataUltimaSaida,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($variacoes as $i => $atributos) {
                $preco = rand(800, 2500);
                $custo = rand(500, $preco - 100);
                $precoPromocional = $isOutlet ? round($preco * (1 - rand(20, 40) / 100), 2) : null;

                $variacaoId = DB::table('produto_variacoes')->insertGetId([
                    'produto_id' => $produtoId,
                    'nome' => "Variação " . ($i + 1),
                    'sku' => strtoupper(Str::random(8)) . $produtosGerados . $i,
                    'codigo_barras' => '789' . rand(100000000, 999999999),
                    'preco' => $preco,
                    'preco_promocional' => $precoPromocional,
                    'custo' => $custo,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($atributos as $atributo => $valor) {
                    DB::table('produto_variacao_atributos')->insert([
                        'id_variacao' => $variacaoId,
                        'atributo' => $atributo,
                        'valor' => $valor,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($isOutlet) {
                    DB::table('estoque')->insert([
                        'id_variacao' => $variacaoId,
                        'id_deposito' => rand(1, 2),
                        'quantidade' => rand(5, 100),
                    ]);
                }
            }

            $produtosGerados++;
        }
    }
}
