<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutosSeeder extends Seeder
{
    /**
     * @throws \Exception
     */
    public function run(): void
    {
        $now = Carbon::now();

        $categorias = DB::table('categorias')->pluck('id', 'nome');
        if ($categorias->isEmpty()) {
            throw new Exception('Nenhuma categoria encontrada.');
        }

        $fornecedores = DB::table('fornecedores')->pluck('id')->toArray();
        if (empty($fornecedores)) {
            throw new Exception('Nenhum fornecedor encontrado.');
        }

        $produtosBase = [
            'Sofá Retrátil 2 Lugares'       => 'Sofá Retrátil',
            'Sofá Modular em L'             => 'Sofá de Canto Modular',
            'Mesa Redonda de Madeira'       => 'Mesa Redonda',
            'Mesa Escritório Compacta'      => 'Mesa de Escritório',
            'Cadeira Gamer Reclinável'      => 'Cadeira Gamer',
            'Cama Casal com Cabeceira'      => 'Cama de Casal',
            'Poltrona de Veludo'            => 'Poltrona',
            'Beliche Madeira Maciça'        => 'Beliche',
            'Nichos Decorativos Hexagonais' => 'Nichos Decorativos',
            'Aparador com Espelho'          => 'Aparador',
        ];

        $linhas = ['Linha Conforto', 'Linha Luxo', 'Linha Design', 'Linha Moderna'];
        $nomes = array_keys($produtosBase);
        $total = 50;

        for ($i = 0; $i < $total; $i++) {
            $baseIndex = $i % count($nomes);
            $nomeBase = $nomes[$baseIndex];
            $categoriaNome = $produtosBase[$nomeBase];
            $linha = $linhas[$i % count($linhas)];
            $nomeFinal = "$nomeBase – $linha";

            $categoriaId = $categorias[$categoriaNome] ?? null;
            if (!$categoriaId) {
                continue;
            }

            $isOutlet = $i >= 40;
            $dias = $isOutlet ? rand(200, 500) : rand(5, 60);
            $dataUltimaSaida = $now->copy()->subDays($dias)->toDateString();

            DB::table('produtos')->insert([
                'nome' => $nomeFinal,
                'descricao' => "O produto $nomeBase da $linha combina funcionalidade e design elegante para sua casa.",
                'id_categoria' => $categoriaId,
                'id_fornecedor' => $fornecedores[array_rand($fornecedores)],
                'altura' => rand(50, 200),
                'largura' => rand(80, 220),
                'profundidade' => rand(50, 150),
                'peso' => rand(10, 100),
                'ativo' => true,
                'is_outlet' => $isOutlet,
                'data_ultima_saida' => $dataUltimaSaida,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
