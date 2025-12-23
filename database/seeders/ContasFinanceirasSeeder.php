<?php

namespace Database\Seeders;

use App\Models\ContaFinanceira;
use Illuminate\Database\Seeder;

class ContasFinanceirasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        ContaFinanceira::insert([
            [
                'nome' => 'Caixa Loja',
                'slug' => 'caixa-loja',
                'tipo' => 'caixa',

                'banco_nome' => null,
                'banco_codigo' => null,
                'agencia' => null,
                'agencia_dv' => null,
                'conta' => null,
                'conta_dv' => null,

                'titular_nome' => null,
                'titular_documento' => null,
                'chave_pix' => null,

                'moeda' => 'BRL',
                'ativo' => true,
                'padrao' => true,
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

                'titular_nome' => null,
                'titular_documento' => null,
                'chave_pix' => null,

                'moeda' => 'BRL',
                'ativo' => true,
                'padrao' => false,
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

                'banco_nome' => null,
                'banco_codigo' => null,
                'agencia' => null,
                'agencia_dv' => null,
                'conta' => null,
                'conta_dv' => null,

                'titular_nome' => null,
                'titular_documento' => null,
                'chave_pix' => null, // se quiser, põe uma chave real aqui

                'moeda' => 'BRL',
                'ativo' => true,
                'padrao' => false,
                'saldo_inicial' => 0,

                'observacoes' => null,
                'meta_json' => null,

                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
