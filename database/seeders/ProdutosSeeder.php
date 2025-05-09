<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seed responsável por popular a tabela de produtos com exemplos reais,
 * já contendo dimensões físicas e associação com categorias previamente existentes.
 */
class ProdutosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $produtos = [
            [
                'nome' => 'Sofá Retrátil',
                'descricao' => 'Sofá retrátil com dois assentos.',
                'id_categoria' => 1,
                'ativo' => true,
                'altura' => 90,
                'largura' => 200,
                'profundidade' => 100,
                'peso' => 80,
            ],
            [
                'nome' => 'Sofá Seccional',
                'descricao' => 'Sofá seccional para ambientes amplos.',
                'id_categoria' => 1,
                'ativo' => true,
                'altura' => 90,
                'largura' => 220,
                'profundidade' => 110,
                'peso' => 90,
            ],
            [
                'nome' => 'Mesa Elegance',
                'descricao' => 'Mesa de jantar elegante.',
                'id_categoria' => 2,
                'ativo' => true,
                'altura' => 75,
                'largura' => 160,
                'profundidade' => 80,
                'peso' => 35,
            ],
            [
                'nome' => 'Mesa Redonda',
                'descricao' => 'Mesa redonda em madeira.',
                'id_categoria' => 2,
                'ativo' => true,
                'altura' => 75,
                'largura' => 120,
                'profundidade' => 120,
                'peso' => 30,
            ],
            [
                'nome' => 'Cadeira Office',
                'descricao' => 'Cadeira ergonômica de escritório.',
                'id_categoria' => 3,
                'ativo' => true,
                'altura' => 100,
                'largura' => 60,
                'profundidade' => 60,
                'peso' => 15,
            ],
            [
                'nome' => 'Cadeira Madeira',
                'descricao' => 'Cadeira rústica em madeira.',
                'id_categoria' => 3,
                'ativo' => true,
                'altura' => 100,
                'largura' => 50,
                'profundidade' => 50,
                'peso' => 12,
            ],
            [
                'nome' => 'Cama Queen',
                'descricao' => 'Cama box queen size.',
                'id_categoria' => 4,
                'ativo' => true,
                'altura' => 50,
                'largura' => 210,
                'profundidade' => 180,
                'peso' => 75,
            ],
            [
                'nome' => 'Cama King Luxo',
                'descricao' => 'Cama king size de luxo.',
                'id_categoria' => 4,
                'ativo' => true,
                'altura' => 50,
                'largura' => 220,
                'profundidade' => 200,
                'peso' => 85,
            ],
            [
                'nome' => 'Estante Modular',
                'descricao' => 'Estante ajustável modular.',
                'id_categoria' => 5,
                'ativo' => true,
                'altura' => 150,
                'largura' => 100,
                'profundidade' => 40,
                'peso' => 40,
            ],
            [
                'nome' => 'Estante Vidro',
                'descricao' => 'Estante com prateleiras de vidro.',
                'id_categoria' => 5,
                'ativo' => true,
                'altura' => 160,
                'largura' => 90,
                'profundidade' => 40,
                'peso' => 45,
            ],
        ];

        foreach ($produtos as $produto) {
            $produto['created_at'] = $now;
            $produto['updated_at'] = $now;
            DB::table('produtos')->insert($produto);
        }
    }
}
