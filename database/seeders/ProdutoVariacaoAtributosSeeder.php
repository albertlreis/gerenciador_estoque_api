<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacaoAtributosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $atributos = [];

        // Mapeamento de atributos por categoria
        $atributosPorCategoria = [
            'Sofá' => [
                ['cor', 'preto'], ['cor', 'cinza'], ['cor', 'bege'], ['tecido', 'veludo'], ['tecido', 'linho']
            ],
            'Mesa' => [
                ['tipo_madeira', 'carvalho'], ['tipo_madeira', 'nogueira'], ['formato', 'redonda'], ['formato', 'retangular']
            ],
            'Cadeira' => [
                ['estrutura', 'ferro'], ['estrutura', 'alumínio'], ['cor', 'preto'], ['revestimento', 'couro'], ['revestimento', 'tecido']
            ],
            'Cama' => [
                ['tamanho', 'solteiro'], ['tamanho', 'casal'], ['material', 'madeira'], ['material', 'mdf']
            ],
            'Estante' => [
                ['material', 'mdf'], ['material', 'madeira'], ['acabamento', 'fosco'], ['acabamento', 'brilhante']
            ],
        ];

        $variacoes = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'pv.produto_id', '=', 'p.id')
            ->join('categorias as c', 'p.id_categoria', '=', 'c.id')
            ->select('pv.id as variacao_id', 'c.nome as categoria_nome')
            ->get();

        foreach ($variacoes as $variacao) {
            // Detecta a categoria base (ex: Sofá de Canto => Sofá)
            $categoriaBase = strtolower($variacao->categoria_nome);
            $base = collect($atributosPorCategoria)->first(function ($_, $key) use ($categoriaBase) {
                return str_contains($categoriaBase, strtolower($key));
            });

            // Se não encontrou categoria compatível, usa fallback genérico
            $opcoes = $base ?? [['cor', 'preto'], ['material', 'madeira']];

            // Seleciona 2 a 3 atributos distintos
            $atributosSelecionados = collect($opcoes)->shuffle()->take(rand(2, 3));

            foreach ($atributosSelecionados as [$atributo, $valor]) {
                $atributos[] = [
                    'id_variacao' => $variacao->variacao_id,
                    'atributo'    => $atributo,
                    'valor'       => $valor,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        DB::table('produto_variacao_atributos')->insert($atributos);
    }
}
