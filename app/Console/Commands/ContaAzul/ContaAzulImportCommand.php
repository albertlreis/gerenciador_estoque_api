<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use Illuminate\Console\Command;

class ContaAzulImportCommand extends Command
{
    public const ENTIDADES_SUPORTADAS = [
        'pessoas' => ContaAzulEntityType::PESSOA,
        'produtos' => ContaAzulEntityType::PRODUTO,
        'vendas' => ContaAzulEntityType::VENDA,
        'financeiro' => ContaAzulEntityType::TITULO,
        'contas_receber' => ContaAzulEntityType::TITULO,
        'contas-receber' => ContaAzulEntityType::TITULO,
        'contas_pagar' => ContaAzulEntityType::CONTA_PAGAR,
        'contas-pagar' => ContaAzulEntityType::CONTA_PAGAR,
        'parcelas' => ContaAzulEntityType::PARCELA,
        'baixas' => ContaAzulEntityType::BAIXA,
        'contas_financeiras' => ContaAzulEntityType::CONTA_FINANCEIRA,
        'contas-financeiras' => ContaAzulEntityType::CONTA_FINANCEIRA,
        'saldos_contas_financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
        'saldos-contas-financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
        'categorias_financeiras' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
        'categorias-financeiras' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
        'centros_custo' => ContaAzulEntityType::CENTRO_CUSTO,
        'centros-custo' => ContaAzulEntityType::CENTRO_CUSTO,
        'formas_pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
        'formas-pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
        'notas' => ContaAzulEntityType::NOTA,
    ];

    protected $signature = 'conta-azul:import {entidade : pessoas|produtos|vendas|financeiro|contas_receber|contas_pagar|parcelas|baixas|contas_financeiras|saldos_contas_financeiras|categorias_financeiras|centros_custo|formas_pagamento|notas} {--loja=}';

    protected $description = 'Importa entidade da Conta Azul para staging';

    public function handle(ImportacaoContaAzulService $import, ContaAzulConnectionService $connections): int
    {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $entidade = (string) $this->argument('entidade');
        if (!isset(self::ENTIDADES_SUPORTADAS[$entidade])) {
            $this->error('Entidade invalida');

            return self::FAILURE;
        }

        $conexao = $connections->latestForLoja($lojaId);
        if (!$conexao) {
            $this->error('Nenhuma conexao encontrada');

            return self::FAILURE;
        }

        $res = $import->importarParaStaging($conexao, self::ENTIDADES_SUPORTADAS[$entidade], $lojaId);
        $this->info('Batch ' . $res['batch_id'] . ' - lidos ' . $res['lidos']);

        return self::SUCCESS;
    }
}
