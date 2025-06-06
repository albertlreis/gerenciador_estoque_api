<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ConfiguracoesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $configuracoes = [
            [
                'chave' => 'dias_previsao_envio_fabrica',
                'label' => 'Dias para Envio à Fábrica após Criação',
                'tipo' => 'integer',
                'valor' => '2',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'chave' => 'dias_previsao_embarque_fabrica',
                'label' => 'Dias para Embarque da Fábrica após Nota Emitida',
                'tipo' => 'integer',
                'valor' => '7',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'chave' => 'dias_previsao_entrega_estoque',
                'label' => 'Dias para Entrega ao Estoque após Embarque',
                'tipo' => 'integer',
                'valor' => '3',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'chave' => 'dias_previsao_envio_cliente',
                'label' => 'Dias para Envio ao Cliente após Estoque',
                'tipo' => 'integer',
                'valor' => '2',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'chave' => 'dias_resposta_consignacao',
                'label' => 'Prazo Padrão de Resposta de Consignação (dias)',
                'tipo' => 'integer',
                'valor' => '10',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'chave' => 'estoque_critico',
                'label' => 'Limite para Estoque Crítico',
                'tipo' => 'integer',
                'valor' => '10',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('configuracoes')->insert($configuracoes);
    }
}
