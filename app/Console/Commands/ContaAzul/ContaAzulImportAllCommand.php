<?php

namespace App\Console\Commands\ContaAzul;

use Illuminate\Console\Command;

class ContaAzulImportAllCommand extends Command
{
    protected $signature = 'conta-azul:import-tudo {--loja=}';

    protected $description = 'Importa todas as entidades suportadas para staging';

    public function handle(): int
    {
        $loja = $this->option('loja');
        $args = $loja !== null && $loja !== '' ? ['--loja' => $loja] : [];

        foreach (['pessoas', 'produtos', 'vendas', 'financeiro', 'baixas', 'notas'] as $e) {
            $this->call('conta-azul:import', array_merge(['entidade' => $e], $args));
        }

        return self::SUCCESS;
    }
}
