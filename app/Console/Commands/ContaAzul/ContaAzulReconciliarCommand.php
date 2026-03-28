<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use Illuminate\Console\Command;

class ContaAzulReconciliarCommand extends Command
{
    protected $signature = 'conta-azul:reconciliar {--loja=} {--recurso=pessoas} {--todos}';

    protected $description = 'Reconcilia um recurso (import + conciliação) ou todos com --todos';

    public function handle(
        ContaAzulConnectionService $connections,
        ReconciliacaoContaAzulService $reconciliacao
    ): int {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $conexao = $connections->latestForLoja($lojaId);
        if (!$conexao) {
            $this->error('Nenhuma conexão encontrada');

            return self::FAILURE;
        }

        if ($this->option('todos')) {
            $reconciliacao->reconciliarTodos($conexao, $lojaId);
        } else {
            $reconciliacao->reconciliarRecurso($conexao, (string) $this->option('recurso'), $lojaId);
        }

        $this->info('OK');

        return self::SUCCESS;
    }
}
