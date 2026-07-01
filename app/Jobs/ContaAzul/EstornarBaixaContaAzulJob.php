<?php

namespace App\Jobs\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Services\AuditoriaLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EstornarBaixaContaAzulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tipoEntidade,
        public readonly int $pagamentoId,
        public readonly ?int $lojaId = null
    ) {
    }

    public function handle(
        ExportacaoContaAzulService $export,
        ContaAzulConnectionService $connections,
        AuditoriaLogService $auditoria
    ): void {
        try {
            $conexao = $connections->latestForLoja($this->lojaId);
            if (!$conexao) {
                return;
            }

            $export->estornarBaixaMapeada($conexao, $this->tipoEntidade, $this->pagamentoId, $this->lojaId);
        } catch (Throwable $e) {
            Log::warning('Falha ao estornar baixa Conta Azul.', [
                'tipo_entidade' => $this->tipoEntidade,
                'pagamento_id' => $this->pagamentoId,
                'loja_id' => $this->lojaId,
                'tentativa' => $this->attempts(),
                'exception' => $e::class,
                'erro' => $e->getMessage(),
            ]);

            $auditoria->registrar([
                'occurred_at' => now(),
                'tipo' => 'integracao',
                'categoria' => 'integracao',
                'nivel' => 'error',
                'modulo' => 'conta_azul',
                'acao' => 'estorno_baixa',
                'status' => 'falha',
                'label' => 'Falha ao estornar baixa Conta Azul',
                'message' => $e->getMessage(),
                'entity_type' => $this->tipoEntidade,
                'entity_id' => $this->pagamentoId,
                'context_json' => [
                    'loja_id' => $this->lojaId,
                    'tipo_entidade' => $this->tipoEntidade,
                    'id_local' => $this->pagamentoId,
                    'direcao' => 'export',
                    'tentativa' => $this->attempts(),
                    'erro_codigo' => $e::class,
                    'erro_mensagem' => $e->getMessage(),
                ],
                'source_system' => 'estoque',
                'source_kind' => 'sync',
                'retention_days' => 365,
            ]);

            throw $e;
        }
    }
}
