<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Enums\PedidoStatus;

class PedidoStatusHistoricoSeeder extends Seeder
{
    public function run(): void
    {
        $pedidos = Pedido::limit(5)->get();

        foreach ($pedidos as $pedido) {
            PedidoStatusHistorico::create([
                'pedido_id' => $pedido->id,
                'status' => PedidoStatus::PEDIDO_CRIADO->value,
                'data_status' => now(),
                'usuario_id' => 1,
                'observacoes' => 'Pedido criado via seeder',
            ]);
        }
    }
}
