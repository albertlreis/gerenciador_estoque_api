<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ProdutoVariacaoAtributosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $atributos = [];

        $atributosPorCategoria = [
            'Sofá' => [
                ['cor', 'preto'], ['cor', 'cinza'], ['cor', 'bege'],
                ['tecido', 'veludo'], ['tecido', 'linho'],
            ],
            'Mesa' => [
                ['tipo_madeira', 'carvalho'], ['tipo_madeira', 'nogueira'],
                ['formato', 'redonda'], ['formato', 'retangular'],
            ],
            'Cadeira' => [
                ['estrutura', 'ferro'], ['estrutura', 'alumínio'],
                ['cor', 'preto'], ['cor', 'cinza'],
                ['revestimento', 'couro'], ['revestimento', 'tecido'],
            ],
            'Cama' => [
                ['tamanho', 'solteiro'], ['tamanho', 'casal'],
                ['material', 'madeira'], ['material', 'mdf'],
            ],
            'Estante' => [
                ['material', 'mdf'], ['material', 'madeira'],
                ['acabamento', 'fosco'], ['acabamento', 'brilhante'],
            ],
        ];

        $variacoes = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'pv.produto_id', '=', 'p.id')
            ->join('categorias as c', 'p.id_categoria', '=', 'c.id')
            ->select('pv.id as variacao_id', 'c.nome as categoria_nome')
            ->get();

        foreach ($variacoes as $variacao) {
            $categoriaNome = strtolower($variacao->categoria_nome);

            $baseAtributos = collect($atributosPorCategoria)->first(
                fn($_, $key) => Str::contains($categoriaNome, strtolower($key))
            );

            $opcoes = $baseAtributos ?? [['cor', 'preto'], ['material', 'madeira']];
            $agrupados = collect($opcoes)->groupBy(fn($item) => $item[0]);

            $chavesSelecionadas = $agrupados->keys()->shuffle()->take(rand(2, 3));

            foreach ($chavesSelecionadas as $chave) {
                $valor = $agrupados[$chave]->random();

                $atributos[] = [
                    'id_variacao' => $variacao->variacao_id,
                    'atributo' => $valor[0],
                    'valor' => $valor[1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('produto_variacao_atributos')->insert($atributos);
    }
}
