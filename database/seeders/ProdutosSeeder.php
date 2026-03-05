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

        $categorias = DB::table('categorias')->pluck('id', 'nome')->all();
        if (empty($categorias)) {
            throw new Exception('Nenhuma categoria encontrada.');
        }

        $fornecedores = DB::table('fornecedores')->orderBy('id')->pluck('id')->toArray();
        if (empty($fornecedores)) {
            throw new Exception('Nenhum fornecedor encontrado.');
        }

        $produtosBase = [
            ['nome' => 'Sofá Retrátil 2 Lugares - Linha Conforto', 'categoria' => 'Sofá Retrátil'],
            ['nome' => 'Sofá Modular em L - Linha Design', 'categoria' => 'Sofá de Canto Modular'],
            ['nome' => 'Mesa Redonda de Madeira - Linha Design', 'categoria' => 'Mesa Redonda'],
            ['nome' => 'Mesa Escritório Compacta - Linha Moderna', 'categoria' => 'Mesa de Escritório'],
            ['nome' => 'Cadeira Gamer Reclinável - Linha Conforto', 'categoria' => 'Cadeira Gamer'],
            ['nome' => 'Cama Casal com Cabeceira - Linha Luxo', 'categoria' => 'Cama de Casal'],
            ['nome' => 'Poltrona de Veludo - Linha Design', 'categoria' => 'Poltrona'],
            ['nome' => 'Beliche Madeira Maciça - Linha Moderna', 'categoria' => 'Beliche'],
            ['nome' => 'Nichos Decorativos Hexagonais - Linha Design', 'categoria' => 'Nichos Decorativos'],
            ['nome' => 'Aparador com Espelho - Linha Luxo', 'categoria' => 'Aparador'],
        ];

        $rows = [];
        foreach ($produtosBase as $index => $produtoBase) {
            $categoriaId = $categorias[$produtoBase['categoria']] ?? null;
            if (!$categoriaId) {
                continue;
            }

            $rows[] = [
                'nome' => $produtoBase['nome'],
                'descricao' => "Produto de referência para fluxos principais de estoque: {$produtoBase['nome']}.",
                'id_categoria' => $categoriaId,
                'id_fornecedor' => $fornecedores[$index % count($fornecedores)],
                'altura' => 80 + $index,
                'largura' => 100 + $index,
                'profundidade' => 60 + $index,
                'peso' => 25 + $index,
                'ativo' => true,
                'motivo_desativacao' => null,
                'estoque_minimo' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('produtos')->upsert(
            $rows,
            ['nome'],
            ['descricao', 'id_categoria', 'id_fornecedor', 'altura', 'largura', 'profundidade', 'peso', 'ativo', 'motivo_desativacao', 'estoque_minimo', 'updated_at']
        );
    }
}
