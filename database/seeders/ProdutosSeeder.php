<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ProdutosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $base = [
            ['nome' => 'Produto Modelo', 'descricao' => 'Produto de demonstração.', 'id_categoria' => 1],
            ['nome' => 'Mesa Decorativa', 'descricao' => 'Mesa pequena para decoração.', 'id_categoria' => 2],
            ['nome' => 'Cadeira Dobrável', 'descricao' => 'Cadeira leve e dobrável.', 'id_categoria' => 3],
            ['nome' => 'Cama Infantil', 'descricao' => 'Cama segura para crianças.', 'id_categoria' => 4],
            ['nome' => 'Estante Alta', 'descricao' => 'Estante com prateleiras altas.', 'id_categoria' => 5],
        ];

        for ($i = 1; $i <= 40; $i++) {
            $ref = $base[$i % count($base)];
            $isOutlet = $i > 30;
            $dias = $isOutlet ? rand(200, 500) : rand(5, 60);
            $dataUltimaSaida = $now->copy()->subDays($dias)->toDateString();

            $produtoId = DB::table('produtos')->insertGetId([
                'nome' => $ref['nome'] . ' #' . $i,
                'descricao' => $ref['descricao'],
                'id_categoria' => $ref['id_categoria'],
                'id_fornecedor' => null,
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

            $qtdVariacoes = rand(1, 3);
            for ($v = 1; $v <= $qtdVariacoes; $v++) {
                $preco = rand(200, 1000);
                $custo = rand(100, $preco - 50);
                $precoPromocional = $isOutlet ? round($preco * (1 - rand(20, 40) / 100), 2) : null;

                $variacaoId = DB::table('produto_variacoes')->insertGetId([
                    'produto_id' => $produtoId,
                    'nome' => "Variação $v",
                    'sku' => strtoupper(Str::random(8)) . $i . $v,
                    'codigo_barras' => '789' . rand(100000000, 999999999),
                    'preco' => $preco,
                    'preco_promocional' => $precoPromocional,
                    'custo' => $custo,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Se for produto outlet, garantir que tenha estoque
                if ($isOutlet) {
                    DB::table('estoque')->insert([
                        'id_variacao' => $variacaoId,
                        'id_deposito' => rand(1, 2),
                        'quantidade' => rand(5, 100),
                    ]);
                }
            }
        }
    }
}
