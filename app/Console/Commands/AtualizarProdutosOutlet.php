<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AtualizarProdutosOutlet extends Command
{
    protected $signature = 'produtos:atualizar-outlet';
    protected $description = 'Atualiza o status de produtos como outlet baseado na última saída';

    public function handle(): void
    {
        $dias = (int) getConfig('dias_para_outlet', 180);
        $limite = Carbon::now()->subDays($dias);

        $atualizados = DB::table('produtos')
            ->where(function ($q) use ($limite) {
                $q->whereNull('data_ultima_saida')
                    ->orWhere('data_ultima_saida', '<', $limite);
            })
            ->update(['is_outlet' => true]);

        $this->info("Produtos marcados como outlet: {$atualizados}");
    }
}
