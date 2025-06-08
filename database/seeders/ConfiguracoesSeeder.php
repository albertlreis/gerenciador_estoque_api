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
                'descricao' => 'Quantidade de dias entre a criação do pedido e o envio previsto para a fábrica.',
            ],
            [
                'chave' => 'dias_previsao_embarque_fabrica',
                'label' => 'Dias para Embarque da Fábrica após Nota Emitida',
                'tipo' => 'integer',
                'valor' => '7',
                'descricao' => 'Prazo previsto em dias entre a emissão da nota e o embarque da fábrica.',
            ],
            [
                'chave' => 'dias_previsao_entrega_estoque',
                'label' => 'Dias para Entrega ao Estoque após Embarque',
                'tipo' => 'integer',
                'valor' => '3',
                'descricao' => 'Número de dias estimado para o produto chegar ao estoque após o embarque da fábrica.',
            ],
            [
                'chave' => 'dias_previsao_envio_cliente',
                'label' => 'Dias para Envio ao Cliente após Estoque',
                'tipo' => 'integer',
                'valor' => '2',
                'descricao' => 'Dias previstos entre a chegada no estoque e o envio ao cliente final.',
            ],
            [
                'chave' => 'dias_resposta_consignacao',
                'label' => 'Prazo Padrão de Resposta de Consignação (dias)',
                'tipo' => 'integer',
                'valor' => '10',
                'descricao' => 'Número de dias que o cliente tem para responder a uma consignação enviada.',
            ],
            [
                'chave' => 'estoque_critico',
                'label' => 'Limite para Estoque Crítico',
                'tipo' => 'integer',
                'valor' => '10',
                'descricao' => 'Quantidade mínima para que o estoque de uma variação seja considerado crítico.',
            ],
            [
                'chave' => 'dias_para_outlet',
                'label' => 'Dias para Produto ir para Outlet',
                'tipo' => 'integer',
                'valor' => '180',
                'descricao' => 'Após esse número de dias sem venda, o produto será marcado como outlet automaticamente.',
            ],
            [
                'chave' => 'prazo_envio_fabrica',
                'label' => 'Prazo de Envio para Fábrica',
                'tipo' => 'integer',
                'valor' => '5',
                'descricao' => 'Prazo em dias para envio do pedido para a fábrica após aprovação.',
            ],
            [
                'chave' => 'prazo_entrega_estoque',
                'label' => 'Prazo de Entrega ao Estoque',
                'tipo' => 'integer',
                'valor' => '7',
                'descricao' => 'Número de dias estimado entre o envio da fábrica e a chegada no estoque.',
            ],
            [
                'chave' => 'prazo_envio_cliente',
                'label' => 'Prazo de Envio ao Cliente',
                'tipo' => 'integer',
                'valor' => '3',
                'descricao' => 'Tempo em dias para envio do produto ao cliente após entrada no estoque.',
            ],
            [
                'chave' => 'prazo_consignacao',
                'label' => 'Prazo Padrão de Consignação',
                'tipo' => 'integer',
                'valor' => '15',
                'descricao' => 'Prazo total, em dias, para uma consignação permanecer válida sem resposta.',
            ],
        ];

        foreach ($configuracoes as &$config) {
            $config['created_at'] = $now;
            $config['updated_at'] = $now;
        }

        DB::table('configuracoes')->insert($configuracoes);
    }
}
