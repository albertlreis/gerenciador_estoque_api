<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutosSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        $produtos = [
            [
                'nome'         => 'Sofá Retrátil',
                'descricao'    => 'Sofá retrátil com dois assentos, ideal para ambientes pequenos.',
                'id_categoria' => 1,
                'ativo'        => true,
                'preco'        => 1299.90,
            ],
            [
                'nome'         => 'Sofá Seccional',
                'descricao'    => 'Sofá seccional para ambientes amplos e confortáveis.',
                'id_categoria' => 1,
                'ativo'        => true,
                'preco'        => 3499.00,
            ],
            [
                'nome'         => 'Mesa de Jantar Elegance',
                'descricao'    => 'Mesa de jantar elegante para refeições em família.',
                'id_categoria' => 2,
                'ativo'        => true,
                'preco'        => 899.50,
            ],
            [
                'nome'         => 'Mesa de Centro Moderna',
                'descricao'    => 'Mesa de centro com design moderno e linhas minimalistas.',
                'id_categoria' => 2,
                'ativo'        => true,
                'preco'        => 499.99,
            ],
            [
                'nome'         => 'Cadeira Ergonômica Office',
                'descricao'    => 'Cadeira ergonômica para longas horas de trabalho no escritório.',
                'id_categoria' => 3,
                'ativo'        => true,
                'preco'        => 299.90,
            ],
            [
                'nome'         => 'Cadeira de Madeira Rústica',
                'descricao'    => 'Cadeira com acabamento rústico que realça o charme natural.',
                'id_categoria' => 3,
                'ativo'        => true,
                'preco'        => 199.90,
            ],
            [
                'nome'         => 'Cama Box Queen',
                'descricao'    => 'Cama box queen size com estrutura robusta e design moderno.',
                'id_categoria' => 4,
                'ativo'        => true,
                'preco'        => 1599.00,
            ],
            [
                'nome'         => 'Cama King Size Luxo',
                'descricao'    => 'Cama king size que une luxo e conforto para um sono de qualidade.',
                'id_categoria' => 4,
                'ativo'        => true,
                'preco'        => 2799.00,
            ],
            [
                'nome'         => 'Estante Modular',
                'descricao'    => 'Estante modular, que se adapta a diversos espaços e necessidades.',
                'id_categoria' => 5,
                'ativo'        => true,
                'preco'        => 499.00,
            ],
            [
                'nome'         => 'Estante Vertical',
                'descricao'    => 'Estante vertical que otimiza ambientes com pouco espaço.',
                'id_categoria' => 5,
                'ativo'        => true,
                'preco'        => 399.90,
            ],
            [
                'nome'         => 'Sofá-cama Compacto',
                'descricao'    => 'Sofá-cama compacto ideal para apartamentos e estúdios.',
                'id_categoria' => 1,
                'ativo'        => true,
                'preco'        => 1899.00,
            ],
            [
                'nome'         => 'Mesa Extensível',
                'descricao'    => 'Mesa extensível para acomodar mais pessoas em ocasiões especiais.',
                'id_categoria' => 2,
                'ativo'        => true,
                'preco'        => 749.50,
            ],
            [
                'nome'         => 'Cadeira Gamer',
                'descricao'    => 'Cadeira gamer com design arrojado e ajuste ergonômico.',
                'id_categoria' => 3,
                'ativo'        => true,
                'preco'        => 399.90,
            ],
            [
                'nome'         => 'Cama Simples',
                'descricao'    => 'Cama simples e funcional para quartos minimalistas.',
                'id_categoria' => 4,
                'ativo'        => true,
                'preco'        => 899.90,
            ],
            [
                'nome'         => 'Estante com Vidro',
                'descricao'    => 'Estante com prateleiras de vidro, trazendo um toque de modernidade.',
                'id_categoria' => 5,
                'ativo'        => true,
                'preco'        => 549.00,
            ],
        ];

        foreach ($produtos as $produto) {
            $produto['created_at'] = $now;
            $produto['updated_at'] = $now;
            DB::table('produtos')->insert($produto);
        }
    }
}
