<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use Illuminate\Console\Command;

class ContaAzulImportCommand extends Command
{
    protected $signature = 'conta-azul:import {entidade : pessoas|produtos|vendas|financeiro|baixas|notas} {--loja=}';

    protected $description = 'Importa entidade da Conta Azul para staging';

    public function handle(ImportacaoContaAzulService $import, ContaAzulConnectionService $connections): int
    {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $map = [
            'pessoas' => \App\Integrations\ContaAzul\ContaAzulEntityType::PESSOA,
            'produtos' => \App\Integrations\ContaAzul\ContaAzulEntityType::PRODUTO,
            'vendas' => \App\Integrations\ContaAzul\ContaAzulEntityType::VENDA,
            'financeiro' => \App\Integrations\ContaAzul\ContaAzulEntityType::TITULO,
            'baixas' => \App\Integrations\ContaAzul\ContaAzulEntityType::BAIXA,
            'notas' => \App\Integrations\ContaAzul\ContaAzulEntityType::NOTA,
        ];

        $entidade = (string) $this->argument('entidade');
        if (!isset($map[$entidade])) {
            $this->error('Entidade inválida');

            return self::FAILURE;
        }

        $conexao = $connections->latestForLoja($lojaId);
        if (!$conexao) {
            $this->error('Nenhuma conexão encontrada');

            return self::FAILURE;
        }

        $res = $import->importarParaStaging($conexao, $map[$entidade], $lojaId);
        $this->info('Batch ' . $res['batch_id'] . ' — lidos ' . $res['lidos']);

        return self::SUCCESS;
    }
}
