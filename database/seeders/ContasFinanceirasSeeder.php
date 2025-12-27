<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContasFinanceirasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = [
            [
                'nome' => 'Caixa Loja',
                'slug' => 'caixa-loja',
                'tipo' => 'caixa',
                'banco_nome' => null, 'banco_codigo' => null, 'agencia' => null, 'agencia_dv' => null, 'conta' => null, 'conta_dv' => null,
                'titular_nome' => null, 'titular_documento' => null, 'chave_pix' => null,
                'moeda' => 'BRL',
                'ativo' => 1,
                'padrao' => 1,
                'saldo_inicial' => 0,
                'observacoes' => null,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Banco Principal',
                'slug' => 'banco-principal',
                'tipo' => 'banco',
                'banco_nome' => 'Banco do Brasil',
                'banco_codigo' => '001',
                'agencia' => '1234',
                'agencia_dv' => null,
                'conta' => '56789',
                'conta_dv' => null,
                'titular_nome' => null, 'titular_documento' => null, 'chave_pix' => null,
                'moeda' => 'BRL',
                'ativo' => 1,
                'padrao' => 0,
                'saldo_inicial' => 0,
                'observacoes' => null,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'PIX',
                'slug' => 'pix',
                'tipo' => 'pix',
                'banco_nome' => null, 'banco_codigo' => null, 'agencia' => null, 'agencia_dv' => null, 'conta' => null, 'conta_dv' => null,
                'titular_nome' => null, 'titular_documento' => null, 'chave_pix' => null,
                'moeda' => 'BRL',
                'ativo' => 1,
                'padrao' => 0,
                'saldo_inicial' => 0,
                'observacoes' => null,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('contas_financeiras')->upsert($rows, ['slug'], [
            'nome','tipo','banco_nome','banco_codigo','agencia','agencia_dv','conta','conta_dv',
            'titular_nome','titular_documento','chave_pix','moeda','ativo','padrao','saldo_inicial',
            'observacoes','meta_json','updated_at'
        ]);
    }
}
