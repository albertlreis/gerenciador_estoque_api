<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CentrosCustoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $items = [
            ['nome' => 'Vendas',          'padrao' => false],
            ['nome' => 'Marketing',       'padrao' => false],
            ['nome' => 'Operação',        'padrao' => true],
            ['nome' => 'Administrativo',  'padrao' => false],
            ['nome' => 'Produção',        'padrao' => false],
            ['nome' => 'Logística',       'padrao' => false],
            ['nome' => 'TI',              'padrao' => false],
        ];

        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                'nome' => $it['nome'],
                'slug' => Str::slug($it['nome']),
                'ordem' => 0,
                'ativo' => 1,
                'padrao' => $it['padrao'] ? 1 : 0,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('centros_custo')->upsert($rows, ['slug'], ['nome','ordem','ativo','padrao','meta_json','updated_at']);
    }
}
