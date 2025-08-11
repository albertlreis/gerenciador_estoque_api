<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OutletTabelasBasicasSeeder extends Seeder{
    public function run(): void{
        $now = Carbon::now();

        $motivos = [
            ['slug'=>'tempo_estoque','nome'=>'Tempo em estoque'],
            ['slug'=>'saiu_linha','nome'=>'Saiu de linha'],
            ['slug'=>'avariado','nome'=>'Avariado'],
            ['slug'=>'devolvido','nome'=>'Devolvido'],
            ['slug'=>'exposicao','nome'=>'Exposição em loja'],
            ['slug'=>'embalagem_danificada','nome'=>'Embalagem danificada'],
            ['slug'=>'baixa_rotatividade','nome'=>'Baixa rotatividade'],
            ['slug'=>'erro_cadastro','nome'=>'Erro de cadastro'],
            ['slug'=>'excedente','nome'=>'Reposição excedente'],
            ['slug'=>'promocao_pontual','nome'=>'Promoção pontual'],
        ];
        foreach ($motivos as $m){
            DB::table('outlet_motivos')->updateOrInsert(['slug'=>$m['slug']], $m+['ativo'=>true,'created_at'=>$now,'updated_at'=>$now]);
        }

        $formas = [
            ['slug'=>'avista','nome'=>'À vista','percentual_desconto_default'=>null,'max_parcelas_default'=>null],
            ['slug'=>'boleto','nome'=>'Boleto','percentual_desconto_default'=>null,'max_parcelas_default'=>null],
            ['slug'=>'cartao','nome'=>'Cartão de Crédito','percentual_desconto_default'=>null,'max_parcelas_default'=>12],
        ];
        foreach ($formas as $f){
            DB::table('outlet_formas_pagamento')->updateOrInsert(['slug'=>$f['slug']], $f+['ativo'=>true,'created_at'=>$now,'updated_at'=>$now]);
        }
    }
}
