<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacoesSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();
        // Obter todos os produtos inseridos
        $produtos = DB::table('produtos')->get();
        $variacoes = [];

        foreach ($produtos as $produto) {
            $produtoId = $produto->id;
            $sku = "SKU-{$produtoId}-01";
            $nomeVar = $produto->nome . " - Variação Padrão";
            $catId = $produto->id_categoria;

            // Definir preços, custos e dimensões de acordo com a categoria
            switch ($catId) {
                case 1: // Sofás
                    $preco = 2500.00;
                    $custo = 1800.00;
                    $peso = 80;
                    $altura = 90;
                    $largura = 200;
                    $profundidade = 100;
                    break;
                case 2: // Mesas
                    $preco = 1200.00;
                    $custo = 800.00;
                    $peso = 30;
                    $altura = 75;
                    $largura = 150;
                    $profundidade = 80;
                    break;
                case 3: // Cadeiras
                    $preco = 600.00;
                    $custo = 400.00;
                    $peso = 10;
                    $altura = 100;
                    $largura = 50;
                    $profundidade = 50;
                    break;
                case 4: // Camas
                    $preco = 2000.00;
                    $custo = 1500.00;
                    $peso = 70;
                    $altura = 50;
                    $largura = 210;
                    $profundidade = 180;
                    break;
                case 5: // Estantes
                    $preco = 1000.00;
                    $custo = 700.00;
                    $peso = 40;
                    $altura = 150;
                    $largura = 100;
                    $profundidade = 40;
                    break;
                default:
                    $preco = 1000.00;
                    $custo = 800.00;
                    $peso = 0;
                    $altura = 0;
                    $largura = 0;
                    $profundidade = 0;
                    break;
            }

            $variacoes[] = [
                'id_produto'   => $produtoId,
                'sku'          => $sku,
                'nome'         => $nomeVar,
                'preco'        => $preco,
                'custo'        => $custo,
                'peso'         => $peso,
                'altura'       => $altura,
                'largura'      => $largura,
                'profundidade' => $profundidade,
                'codigo_barras'=> '123456789012',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        DB::table('produto_variacoes')->insert($variacoes);
    }
}
