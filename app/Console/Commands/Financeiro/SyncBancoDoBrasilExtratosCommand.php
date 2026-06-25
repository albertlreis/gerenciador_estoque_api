<?php

namespace App\Console\Commands\Financeiro;

use App\Integrations\Bancos\BancoDoBrasil\BancoDoBrasilExtratosService;
use Illuminate\Console\Command;

class SyncBancoDoBrasilExtratosCommand extends Command
{
    protected $signature = 'financeiro:sync-bb-extratos {--days=7} {--conta=}';

    protected $description = 'Sincroniza extratos do Banco do Brasil para conciliacao bancaria.';

    public function handle(BancoDoBrasilExtratosService $service): int
    {
        $days = max(1, min((int) $this->option('days'), 90));
        $conta = $this->option('conta');
        $contaId = $conta !== null && $conta !== '' ? (int) $conta : null;

        $result = $service->sincronizarTodas($days, $contaId);

        $this->info(sprintf(
            'BB Extratos: %d sucesso(s), %d falha(s).',
            $result['success'],
            $result['failed']
        ));

        foreach ($result['results'] as $item) {
            $line = sprintf(
                'Conta %d: %s',
                (int) $item['conta_financeira_id'],
                (string) $item['status']
            );

            if (($item['status'] ?? null) === 'ok') {
                $this->line($line . ' importacao #' . (int) $item['importacao_id']);
            } else {
                $this->warn($line . ' - ' . (string) ($item['message'] ?? 'erro'));
            }
        }

        return $result['success'] > 0 || $result['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
