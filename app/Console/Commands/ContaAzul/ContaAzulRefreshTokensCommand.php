<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use Illuminate\Console\Command;

class ContaAzulRefreshTokensCommand extends Command
{
    protected $signature = 'conta-azul:refresh-tokens';

    protected $description = 'Renova access tokens Conta Azul (conexões ativas)';

    public function handle(ContaAzulConnectionService $connections): int
    {
        $conexoes = ContaAzulConexao::query()->where('status', 'ativa')->orderBy('id')->get();

        foreach ($conexoes as $c) {
            try {
                $connections->getValidAccessToken($c);
                $this->info('Conexão ' . $c->id . ' OK');
            } catch (\Throwable $e) {
                $this->error('Conexão ' . $c->id . ' falhou: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
