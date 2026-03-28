<?php

namespace App\Jobs\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconciliarContaAzulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?int $lojaId = null,
        public readonly string $recurso = 'pessoas',
        public readonly bool $todos = false
    ) {
    }

    public function handle(ContaAzulConnectionService $connections, ReconciliacaoContaAzulService $reconciliacao): void
    {
        $conexao = $connections->latestForLoja($this->lojaId);
        if (!$conexao) {
            return;
        }

        if ($this->todos) {
            $reconciliacao->reconciliarTodos($conexao, $this->lojaId);

            return;
        }

        $reconciliacao->reconciliarRecurso($conexao, $this->recurso, $this->lojaId);
    }
}
