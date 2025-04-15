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

        // Inserir Itens dos Pedidos utilizando o campo id_produto
        DB::table('pedido_itens')->insert([
            // Pedido 1: 2 itens
            [
                'id_pedido'      => 1,
                'id_produto'     => 1, // Produto: Sofá Retrátil (anteriormente id_variacao: 1)
                'quantidade'     => 2,
                'preco_unitario' => 2500.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 1,
                'id_produto'     => 5, // Produto: Cadeira Ergonômica Office (anteriormente id_variacao: 5)
                'quantidade'     => 1,
                'preco_unitario' => 600.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 2: 1 item
            [
                'id_pedido'      => 2,
                'id_produto'     => 3, // Produto: Mesa de Jantar Elegance (anteriormente id_variacao: 3)
                'quantidade'     => 4,
                'preco_unitario' => 1200.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 3: 1 item
            [
                'id_pedido'      => 3,
                'id_produto'     => 7, // Produto: Cama Box Queen (anteriormente id_variacao: 7)
                'quantidade'     => 1,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 4: 2 itens
            [
                'id_pedido'      => 4,
                'id_produto'     => 8, // Produto: Cama King Size Luxo (anteriormente id_variacao: 8)
                'quantidade'     => 2,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 4,
                'id_produto'     => 2, // Produto: Sofá Seccional (anteriormente id_variacao: 2)
                'quantidade'     => 1,
                'preco_unitario' => 2500.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 5: 2 itens
            [
                'id_pedido'      => 5,
                'id_produto'     => 9, // Produto: Estante Modular (anteriormente id_variacao: 9)
                'quantidade'     => 3,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 5,
                'id_produto'     => 12, // Produto: Mesa Extensível (anteriormente id_variacao: 12)
                'quantidade'     => 1,
                'preco_unitario' => 1200.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 6: 1 item
            [
                'id_pedido'      => 6,
                'id_produto'     => 6, // Produto: Cadeira de Madeira Rústica (anteriormente id_variacao: 6)
                'quantidade'     => 2,
                'preco_unitario' => 600.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 7: 2 itens
            [
                'id_pedido'      => 7,
                'id_produto'     => 10, // Produto: Estante Vertical (anteriormente id_variacao: 10)
                'quantidade'     => 2,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 7,
                'id_produto'     => 14, // Produto: Cama Simples (anteriormente id_variacao: 14)
                'quantidade'     => 1,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 8: 1 item
            [
                'id_pedido'      => 8,
                'id_produto'     => 15, // Produto: Estante com Vidro (anteriormente id_variacao: 15)
                'quantidade'     => 1,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ]);
    }
}
