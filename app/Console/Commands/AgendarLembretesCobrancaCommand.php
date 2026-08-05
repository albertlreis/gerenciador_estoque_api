<?php

namespace App\Console\Commands;

use App\Services\Comunicacao\ComunicacaoOutboxService;
use Illuminate\Console\Command;

class AgendarLembretesCobrancaCommand extends Command
{
    protected $signature = 'comunicacao:agendar-cobrancas';
    protected $description = 'Cria itens idempotentes de comunicação para os marcos de cobrança do dia';

    public function handle(ComunicacaoOutboxService $service): int
    {
        if (! (bool) config('comunicacao.processing_enabled', false)) {
            $this->info('Processamento de comunicação desabilitado.');
            return self::SUCCESS;
        }

        $this->info('Itens criados: '.$service->agendarCobrancasHoje());
        return self::SUCCESS;
    }
}
