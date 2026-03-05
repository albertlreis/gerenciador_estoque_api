<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FormasPagamentoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $nomes = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];

        $rows = [];
        foreach ($nomes as $nome) {
            $rows[] = [
                'nome' => $nome,
                'slug' => Str::slug($nome),
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('formas_pagamento')->upsert(
            $rows,
            ['slug'],
            ['nome', 'ativo', 'updated_at']
        );
    }
}
