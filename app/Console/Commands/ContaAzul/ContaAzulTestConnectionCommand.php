<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use Illuminate\Console\Command;

class ContaAzulTestConnectionCommand extends Command
{
    protected $signature = 'conta-azul:test-connection {--loja=}';

    protected $description = 'Executa healthcheck da conexão Conta Azul';

    public function handle(ContaAzulConnectionService $connections): int
    {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $conexao = $connections->latestForLoja($lojaId);
        if (!$conexao) {
            $this->error('Nenhuma conexão encontrada.');

            return self::FAILURE;
        }

        $ok = $connections->healthcheck($conexao);
        $this->info($ok ? 'OK' : 'FALHA');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
