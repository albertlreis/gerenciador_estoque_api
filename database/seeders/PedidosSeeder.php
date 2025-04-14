<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PedidosSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        // Inserir Pedidos (8 registros)
        DB::table('pedidos')->insert([
            [
                'id_cliente'   => 1,
                'data_pedido'  => $now->copy()->subDays(10),
                'status'       => 'novo',
                'observacoes'  => 'Primeiro pedido',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 2,
                'data_pedido'  => $now->copy()->subDays(8),
                'status'       => 'finalizado',
                'observacoes'  => 'Entrega realizada',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 3,
                'data_pedido'  => $now->copy()->subDays(7),
                'status'       => 'pendente',
                'observacoes'  => 'Aguardando pagamento',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 4,
                'data_pedido'  => $now->copy()->subDays(5),
                'status'       => 'novo',
                'observacoes'  => 'Pedido em processamento',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 1,
                'data_pedido'  => $now->copy()->subDays(3),
                'status'       => 'finalizado',
                'observacoes'  => 'Pedido entregue',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 2,
                'data_pedido'  => $now->copy()->subDays(2),
                'status'       => 'cancelado',
                'observacoes'  => 'Cliente cancelou',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 3,
                'data_pedido'  => $now->copy()->subDays(1),
                'status'       => 'novo',
                'observacoes'  => 'Pedido recebido',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 4,
                'data_pedido'  => $now,
                'status'       => 'pendente',
                'observacoes'  => 'Pagamento pendente',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ]);

        // Inserir Itens dos Pedidos
        DB::table('pedido_itens')->insert([
            // Pedido 1: 2 itens
            [
                'id_pedido'      => 1,
                'id_variacao'    => 1, // Sofá Retrátil
                'quantidade'     => 2,
                'preco_unitario' => 2500.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 1,
                'id_variacao'    => 5, // Cadeira Ergonômica Office
                'quantidade'     => 1,
                'preco_unitario' => 600.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 2: 1 item
            [
                'id_pedido'      => 2,
                'id_variacao'    => 3, // Mesa de Jantar Elegance
                'quantidade'     => 4,
                'preco_unitario' => 1200.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 3: 1 item
            [
                'id_pedido'      => 3,
                'id_variacao'    => 7, // Cama Box Queen
                'quantidade'     => 1,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 4: 2 itens
            [
                'id_pedido'      => 4,
                'id_variacao'    => 8, // Cama King Size Luxo
                'quantidade'     => 2,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 4,
                'id_variacao'    => 2, // Sofá Seccional
                'quantidade'     => 1,
                'preco_unitario' => 2500.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 5: 2 itens
            [
                'id_pedido'      => 5,
                'id_variacao'    => 9, // Estante Modular
                'quantidade'     => 3,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 5,
                'id_variacao'    => 12, // Mesa Extensível
                'quantidade'     => 1,
                'preco_unitario' => 1200.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 6: 1 item
            [
                'id_pedido'      => 6,
                'id_variacao'    => 6, // Cadeira de Madeira Rústica
                'quantidade'     => 2,
                'preco_unitario' => 600.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 7: 2 itens
            [
                'id_pedido'      => 7,
                'id_variacao'    => 10, // Estante Vertical
                'quantidade'     => 2,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 7,
                'id_variacao'    => 14, // Cama Simples
                'quantidade'     => 1,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 8: 1 item
            [
                'id_pedido'      => 8,
                'id_variacao'    => 15, // Estante com Vidro
                'quantidade'     => 1,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ]);
    }
}
