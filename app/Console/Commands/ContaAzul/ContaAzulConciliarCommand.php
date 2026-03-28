<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use Illuminate\Console\Command;

class ContaAzulConciliarCommand extends Command
{
    protected $signature = 'conta-azul:conciliar {--loja=}';

    protected $description = 'Executa conciliação de pessoas (staging → mapeamentos)';

    public function handle(ConciliacaoContaAzulService $conciliacao): int
    {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $res = $conciliacao->conciliarPessoas($lojaId);
        $this->info(json_encode($res, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
