<?php

namespace App\Console\Commands\ContaAzul;

use Illuminate\Console\Command;

class ContaAzulImportAllCommand extends Command
{
    public const ENTIDADES_FINANCEIRO_COMPLETO = [
        'contas_financeiras',
        'categorias_financeiras',
        'centros_custo',
        'pessoas',
        'produtos',
        'vendas',
        'financeiro',
        'contas_pagar',
        'parcelas',
        'baixas',
        'saldos_contas_financeiras',
        'formas_pagamento',
        'notas',
    ];

    protected $signature = 'conta-azul:import-tudo {--loja=}';

    protected $description = 'Importa todas as entidades suportadas para staging';

    public function handle(): int
    {
        $loja = $this->option('loja');
        $args = $loja !== null && $loja !== '' ? ['--loja' => $loja] : [];

        foreach (self::ENTIDADES_FINANCEIRO_COMPLETO as $entidade) {
            $this->call('conta-azul:import', array_merge(['entidade' => $entidade], $args));
        }

        return self::SUCCESS;
    }
}
