<?php

namespace App\Console\Commands;

use App\Services\Comunicacao\ComunicacaoOutboxService;
use Illuminate\Console\Command;

class ProcessarComunicacaoOutboxCommand extends Command
{
    protected $signature = 'comunicacao:processar-outbox {--limit=50}';
    protected $description = 'Processa a saída transacional de comunicação do Sierra';

    public function handle(ComunicacaoOutboxService $service): int
    {
        if (! (bool) config('comunicacao.processing_enabled', false)) {
            $this->info('Processamento de comunicação desabilitado.');
            return self::SUCCESS;
        }

        $resultado = $service->processarPendentes(max(1, (int) $this->option('limit')));
        $this->info("Enviados: {$resultado['enviados']}; falhos: {$resultado['falhos']}");

        return self::SUCCESS;
    }
}
