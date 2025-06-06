<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EstoqueMovimentacoesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $estoque = DB::table('estoque')->get();

        foreach ($estoque as $registro) {
            // ENTRADA OBRIGATÓRIA
            DB::table('estoque_movimentacoes')->insert([
                'id_variacao' => $registro->id_variacao,
                'id_deposito_origem' => null,
                'id_deposito_destino' => $registro->id_deposito,
                'tipo' => 'entrada',
                'quantidade' => $registro->quantidade,
                'observacao' => 'Entrada inicial para popular estoque',
                'data_movimentacao' => $now->copy()->subDays(rand(10, 90)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // SAÍDA OPCIONAL
            if (rand(0, 1)) {
                $saidaQtd = rand(1, (int)($registro->quantidade * 0.5));
                DB::table('estoque_movimentacoes')->insert([
                    'id_variacao' => $registro->id_variacao,
                    'id_deposito_origem' => $registro->id_deposito,
                    'id_deposito_destino' => null,
                    'tipo' => 'saida',
                    'quantidade' => $saidaQtd,
                    'observacao' => 'Saída por pedido de venda simulado',
                    'data_movimentacao' => $now->copy()->subDays(rand(1, 9)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // TRANSFERÊNCIAS ENTRE DEPÓSITOS
        $agrupado = DB::table('estoque')
            ->select('id_variacao', DB::raw('GROUP_CONCAT(id_deposito) as depositos'))
            ->groupBy('id_variacao')
            ->havingRaw('COUNT(*) >= 2')
            ->get();

        foreach ($agrupado as $grupo) {
            $depositos = explode(',', $grupo->depositos);
            [$origem, $destino] = array_slice($depositos, 0, 2);

            DB::table('estoque_movimentacoes')->insert([
                'id_variacao' => $grupo->id_variacao,
                'id_deposito_origem' => $origem,
                'id_deposito_destino' => $destino,
                'tipo' => 'transferencia',
                'quantidade' => rand(1, 10),
                'observacao' => 'Transferência entre depósitos simulada',
                'data_movimentacao' => $now->copy()->subDays(rand(2, 30)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
