<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PedidosSeeder extends Seeder
{
    public function run()
    {
        $clientes = DB::table('clientes')->pluck('id')->toArray();
        $usuarios = DB::table('acesso_usuarios')->pluck('id')->toArray();
        $parceiros = DB::table('parceiros')->pluck('id')->toArray();
        $variacoes = DB::table('produto_variacoes')->get();

        // Gera pedidos para os últimos 6 meses
        foreach (range(0, 5) as $i) {
            $dataBase = Carbon::now()->subMonths($i)->startOfMonth();

            // Gera de 5 a 15 pedidos por mês
            $qtdPedidos = rand(5, 15);

            for ($j = 0; $j < $qtdPedidos; $j++) {
                $id_cliente = fake()->randomElement($clientes);
                $id_usuario = fake()->randomElement($usuarios);
                $id_parceiro = fake()->optional()->randomElement($parceiros);

                $dataPedido = $dataBase->copy()->addDays(rand(0, 27))->setTime(rand(8, 18), rand(0, 59));
                $status = fake()->randomElement(['confirmado', 'cancelado', 'rascunho']);
                $observacoes = fake()->boolean(40) ? fake()->sentence() : null;

                // Insere o pedido (valor total zerado por enquanto)
                $id_pedido = DB::table('pedidos')->insertGetId([
                    'id_cliente'   => $id_cliente,
                    'id_usuario'   => $id_usuario,
                    'id_parceiro'  => $id_parceiro,
                    'data_pedido'  => $dataPedido,
                    'status'       => $status,
                    'valor_total'  => 0,
                    'observacoes'  => $observacoes,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                // Insere de 1 a 4 itens no pedido
                $itens = [];
                $valorTotal = 0;

                foreach (fake()->randomElements($variacoes, rand(1, 4)) as $variacao) {
                    $qtd = fake()->numberBetween(1, 5);
                    $preco = $variacao->preco ?? fake()->randomFloat(2, 10, 100);
                    $subtotal = $qtd * $preco;

                    $itens[] = [
                        'id_pedido'      => $id_pedido,
                        'id_variacao'    => $variacao->id,
                        'quantidade'     => $qtd,
                        'preco_unitario' => $preco,
                        'subtotal'       => $subtotal,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];

                    $valorTotal += $subtotal;
                }

                DB::table('pedido_itens')->insert($itens);

                // Atualiza valor total do pedido
                DB::table('pedidos')->where('id', $id_pedido)->update([
                    'valor_total' => $valorTotal,
                ]);
            }
        }
    }
}
