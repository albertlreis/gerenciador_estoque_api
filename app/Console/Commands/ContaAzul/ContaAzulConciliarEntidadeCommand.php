<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use Illuminate\Console\Command;

class ContaAzulConciliarEntidadeCommand extends Command
{
    protected $signature = 'conta-azul:conciliar-entidade {entidade : pessoas|produtos|vendas|titulos|baixas|tudo} {--loja=}';

    protected $description = 'Executa conciliação por entidade';

    public function handle(ConciliacaoContaAzulService $conciliacao): int
    {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $entidade = (string) $this->argument('entidade');
        $res = match ($entidade) {
            'pessoas' => $conciliacao->conciliarPessoas($lojaId),
            'produtos' => $conciliacao->conciliarProdutos($lojaId),
            'vendas' => $conciliacao->conciliarVendas($lojaId),
            'titulos' => $conciliacao->conciliarTitulos($lojaId),
            'baixas' => $conciliacao->conciliarBaixas($lojaId),
            'tudo' => $conciliacao->conciliarTudo($lojaId),
            default => null,
        };

        if ($res === null) {
            $this->error('Entidade inválida');

            return self::FAILURE;
        }

        $this->info(json_encode($res, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
