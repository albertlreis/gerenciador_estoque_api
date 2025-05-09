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

        for ($i = 0; $i < 20; $i++) {
            $id_cliente = fake()->randomElement($clientes);
            $id_usuario = fake()->randomElement($usuarios);
            $id_parceiro = fake()->optional()->randomElement($parceiros);

            $data = Carbon::now()->subDays(rand(0, 60));
            $status = fake()->randomElement(['confirmado', 'cancelado', 'rascunho']);
            $observacoes = fake()->boolean(50) ? fake()->sentence() : null;

            // Insere o pedido
            $id_pedido = DB::table('pedidos')->insertGetId([
                'id_cliente'   => $id_cliente,
                'id_usuario'   => $id_usuario,
                'id_parceiro'  => $id_parceiro,
                'data_pedido'  => $data,
                'status'       => $status,
                'valor_total'  => 0, // será atualizado depois
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
                    'id_pedido'     => $id_pedido,
                    'id_variacao'   => $variacao->id,
                    'quantidade'    => $qtd,
                    'preco_unitario'=> $preco,
//                    'subtotal'      => $subtotal,
                    'created_at'    => now(),
                    'updated_at'    => now(),
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
