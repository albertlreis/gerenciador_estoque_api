<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalizacaoDimensoesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = [
            ['nome' => 'Corredor', 'placeholder' => '1', 'ordem' => 1, 'ativo' => true],
            ['nome' => 'Prateleira', 'placeholder' => 'A', 'ordem' => 2, 'ativo' => true],
            ['nome' => 'Nível', 'placeholder' => '1', 'ordem' => 3, 'ativo' => true],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('localizacao_dimensoes')->upsert(
            $rows,
            ['nome'],
            ['placeholder', 'ordem', 'ativo', 'updated_at']
        );
    }
}
