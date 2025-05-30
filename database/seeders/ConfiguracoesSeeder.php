<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConfiguracoesSeeder extends Seeder
{
    public function run(): void
    {
        $agora = now();

        $configuracoes = [
            // Configurações do Outlet
            [
                'chave' => 'dias_para_outlet',
                'valor' => '180',
                'label' => 'Dias sem movimentação para considerar Outlet',
                'tipo' => 'number',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            [
                'chave' => 'desconto_maximo_outlet',
                'valor' => '30',
                'label' => 'Percentual máximo de desconto no Outlet (%)',
                'tipo' => 'number',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],

            // Prazos do fluxo de pedidos
            [
                'chave' => 'prazo_envio_fabrica',
                'valor' => '5',
                'label' => 'Prazo envio fábrica (dias)',
                'tipo' => 'number',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            [
                'chave' => 'prazo_entrega_estoque',
                'valor' => '7',
                'label' => 'Prazo entrega ao estoque (dias)',
                'tipo' => 'number',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            [
                'chave' => 'prazo_envio_cliente',
                'valor' => '3',
                'label' => 'Prazo envio ao cliente (dias)',
                'tipo' => 'number',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            [
                'chave' => 'prazo_consignacao',
                'valor' => '15',
                'label' => 'Prazo consignação (dias)',
                'tipo' => 'number',
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
        ];

        foreach ($configuracoes as $config) {
            DB::table('configuracoes')->updateOrInsert(
                ['chave' => $config['chave']],
                $config
            );
        }
    }
}
