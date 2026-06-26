<?php

namespace App\Jobs\ContaAzul;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Models\ContaPagarPagamento;
use App\Services\AuditoriaLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExportBaixaContaPagarContaAzulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $pagamentoId,
        public readonly ?int $lojaId = null
    ) {
    }

    public function handle(
        ExportacaoContaAzulService $export,
        ContaAzulConnectionService $connections,
        AuditoriaLogService $auditoria
    ): void
    {
        try {
            $conexao = $connections->latestForLoja($this->lojaId);
            if (!$conexao) {
                return;
            }

            $pagamento = ContaPagarPagamento::query()->findOrFail($this->pagamentoId);
            $export->exportarBaixaContaPagar($conexao, $pagamento, $this->lojaId);
        } catch (Throwable $e) {
            $this->registrarFalha($auditoria, $e);
            throw $e;
        }
    }

    private function registrarFalha(AuditoriaLogService $auditoria, Throwable $e): void
    {
        $auditoria->registrar([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'error',
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'status' => 'falha',
            'label' => 'Falha ao exportar baixa de conta a pagar para Conta Azul',
            'message' => $e->getMessage(),
            'entity_type' => ContaAzulEntityType::BAIXA_CONTA_PAGAR,
            'entity_id' => $this->pagamentoId,
            'context_json' => [
                'loja_id' => $this->lojaId,
                'tipo_entidade' => ContaAzulEntityType::BAIXA_CONTA_PAGAR,
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
    }
}

