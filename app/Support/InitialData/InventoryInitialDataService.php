<?php

namespace App\Support\InitialData;

use App\Models\AssistenciaDefeito;
use App\Models\AreaEstoque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InventoryInitialDataService
{
    public function runBootstrap(?callable $logger = null): void
    {
        $steps = [
            'Configurações obrigatórias' => 'seedConfiguracoes',
            'Feriados obrigatórios' => 'seedFeriados',
            'Formas de pagamento obrigatórias' => 'seedFormasPagamento',
            'Catálogos de outlet' => 'seedOutletCatalogos',
            'Catálogo de defeitos de assistência' => 'seedAssistenciaDefeitos',
            'Áreas padrão de estoque' => 'seedAreasEstoque',
            'Dimensões padrão de localização' => 'seedLocalizacaoDimensoes',
            'Depósito sistêmico de assistência' => 'ensureAssistenciaDeposito',
        ];

        foreach ($steps as $label => $method) {
            if ($logger) {
                $logger($label);
            }
            $this->{$method}();
        }
    }

    public function seedConfiguracoes(): void
    {
        $now = now();
        $existentes = DB::table('configuracoes')->pluck('valor', 'chave');

        $rows = [
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
            [
                'chave' => 'dias_previsao_entrega_cliente',
                'label' => 'Dias para Entrega ao Cliente após Envio',
                'tipo' => 'integer',
                'valor' => '3',
                'descricao' => 'Número de dias estimado para o cliente receber o produto após o envio.',
            ],
        ];

        foreach ($rows as &$row) {
            $row['valor'] = (string) ($existentes[$row['chave']] ?? $row['valor']);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('configuracoes')->upsert(
            $rows,
            ['chave'],
            ['label', 'tipo', 'valor', 'descricao', 'updated_at']
        );
    }

    public function seedFeriados(): void
    {
        $anoAtual = (int) now('America/Belem')->year;
        $anos = [$anoAtual, $anoAtual + 1];
        $uf = (string) config('holidays.default_uf', 'PA');
        $now = now();

        $fixos = [
            ['mes_dia' => '01-01', 'nome' => 'Confraternização Universal', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '04-21', 'nome' => 'Tiradentes', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '05-01', 'nome' => 'Dia do Trabalho', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '09-07', 'nome' => 'Independência do Brasil', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '10-12', 'nome' => 'Nossa Senhora Aparecida', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '11-02', 'nome' => 'Finados', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '11-15', 'nome' => 'Proclamação da República', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '12-25', 'nome' => 'Natal', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '08-15', 'nome' => 'Adesão do Pará', 'escopo' => 'estadual', 'uf' => $uf],
        ];

        $rows = [];
        foreach ($anos as $ano) {
            foreach ($fixos as $feriado) {
                $rows[] = [
                    'data' => "{$ano}-{$feriado['mes_dia']}",
                    'nome' => $feriado['nome'],
                    'escopo' => $feriado['escopo'],
                    'uf' => $feriado['uf'],
                    'fonte' => 'manual',
                    'ano' => $ano,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach ($rows as $row) {
            DB::table('feriados')->updateOrInsert(
                [
                    'data' => $row['data'],
                    'escopo' => $row['escopo'],
                    'uf' => $row['uf'],
                ],
                [
                    'nome' => $row['nome'],
                    'fonte' => $row['fonte'],
                    'ano' => $row['ano'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ]
            );
        }
    }

    public function seedFormasPagamento(): void
    {
        $now = now();
        $nomes = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];

        $rows = [];
        foreach ($nomes as $nome) {
            $rows[] = [
                'nome' => $nome,
                'slug' => Str::slug($nome),
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('formas_pagamento')->upsert(
            $rows,
            ['slug'],
            ['nome', 'ativo', 'updated_at']
        );
    }

    public function seedOutletCatalogos(): void
    {
        $now = now();

        $motivos = [
            ['slug' => 'tempo_estoque', 'nome' => 'Tempo em estoque'],
            ['slug' => 'saiu_linha', 'nome' => 'Saiu de linha'],
            ['slug' => 'avariado', 'nome' => 'Avariado'],
            ['slug' => 'devolvido', 'nome' => 'Devolvido'],
            ['slug' => 'exposicao', 'nome' => 'Exposição em loja'],
            ['slug' => 'embalagem_danificada', 'nome' => 'Embalagem danificada'],
            ['slug' => 'baixa_rotatividade', 'nome' => 'Baixa rotatividade'],
            ['slug' => 'erro_cadastro', 'nome' => 'Erro de cadastro'],
            ['slug' => 'excedente', 'nome' => 'Reposição excedente'],
            ['slug' => 'promocao_pontual', 'nome' => 'Promoção pontual'],
        ];

        foreach ($motivos as $motivo) {
            DB::table('outlet_motivos')->updateOrInsert(
                ['slug' => $motivo['slug']],
                $motivo + ['ativo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $formas = [
            ['slug' => 'avista', 'nome' => 'À vista', 'percentual_desconto_default' => null, 'max_parcelas_default' => null],
            ['slug' => 'boleto', 'nome' => 'Boleto', 'percentual_desconto_default' => null, 'max_parcelas_default' => null],
            ['slug' => 'cartao', 'nome' => 'Cartão de Crédito', 'percentual_desconto_default' => null, 'max_parcelas_default' => 12],
        ];

        foreach ($formas as $forma) {
            DB::table('outlet_formas_pagamento')->updateOrInsert(
                ['slug' => $forma['slug']],
                $forma + ['ativo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function seedAssistenciaDefeitos(): void
    {
        $rows = [
            ['codigo' => 'EST-TR', 'descricao' => 'Estrutura trincada', 'critico' => true, 'ativo' => true],
            ['codigo' => 'EST-FO', 'descricao' => 'Estrutura fora de esquadro', 'critico' => false, 'ativo' => true],
            ['codigo' => 'REV-SOL', 'descricao' => 'Revestimento descolando', 'critico' => false, 'ativo' => true],
            ['codigo' => 'PED-RS', 'descricao' => 'Pé desalinhado/solto', 'critico' => false, 'ativo' => true],
            ['codigo' => 'PNT-BR', 'descricao' => 'Pintura com bolhas/rachada', 'critico' => false, 'ativo' => true],
            ['codigo' => 'TEC-DEF', 'descricao' => 'Tecido com defeito', 'critico' => false, 'ativo' => true],
            ['codigo' => 'MEC-RUI', 'descricao' => 'Mecanismo ruidoso', 'critico' => false, 'ativo' => true],
            ['codigo' => 'SOLD-FL', 'descricao' => 'Solda falha', 'critico' => true, 'ativo' => true],
        ];

        foreach ($rows as $row) {
            AssistenciaDefeito::query()->updateOrCreate(
                ['codigo' => $row['codigo']],
                $row
            );
        }
    }

    public function seedAreasEstoque(): array
    {
        $rows = [
            ['nome' => 'Assistência', 'descricao' => null],
            ['nome' => 'Devolução', 'descricao' => null],
            ['nome' => 'Tampos Avariados', 'descricao' => null],
            ['nome' => 'Tampos Clientes', 'descricao' => null],
            ['nome' => 'Avarias', 'descricao' => null],
        ];

        $ids = [];
        foreach ($rows as $row) {
            $area = AreaEstoque::query()->updateOrCreate(
                ['nome' => $row['nome']],
                ['descricao' => $row['descricao']]
            );

            $ids[] = (int) $area->id;
        }

        return $ids;
    }

    public function seedLocalizacaoDimensoes(): void
    {
        $now = now();

        $rows = [
            ['nome' => 'Corredor', 'placeholder' => '1', 'ordem' => 1, 'ativo' => true],
            ['nome' => 'Prateleira', 'placeholder' => 'A', 'ordem' => 2, 'ativo' => true],
            ['nome' => 'Nível', 'placeholder' => '1', 'ordem' => 3, 'ativo' => true],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('localizacao_dimensoes')->upsert(
            $rows,
            ['nome'],
            ['placeholder', 'ordem', 'ativo', 'updated_at']
        );
    }

    public function ensureAssistenciaDeposito(): void
    {
        if (!Schema::hasTable('depositos') || !Schema::hasColumn('depositos', 'nome')) {
            return;
        }

        $payload = ['nome' => 'ASSISTÊNCIA'];

        if (Schema::hasColumn('depositos', 'sigla')) {
            $payload['sigla'] = 'AST';
        }
        if (Schema::hasColumn('depositos', 'ativo')) {
            $payload['ativo'] = true;
        }
        if (Schema::hasColumn('depositos', 'descricao')) {
            $payload['descricao'] = 'Depósito sistêmico para itens em assistência técnica';
        }
        if (Schema::hasColumn('depositos', 'localizacao')) {
            $payload['localizacao'] = 'Centro de Serviços';
        }

        DB::table('depositos')->updateOrInsert(['nome' => $payload['nome']], $payload);
    }
}
