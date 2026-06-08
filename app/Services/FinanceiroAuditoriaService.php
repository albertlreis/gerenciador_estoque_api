<?php

namespace App\Services;

use App\Support\Auditoria\AuditoriaDiff;
use Illuminate\Database\Eloquent\Model;

class FinanceiroAuditoriaService
{
    private const FINANCEIRO_AUDIT_FIELDS = [
        'descricao',
        'numero_documento',
        'data_emissao',
        'data_vencimento',
        'data_pagamento',
        'valor',
        'valor_bruto',
        'desconto',
        'juros',
        'multa',
        'valor_liquido',
        'valor_recebido',
        'saldo_aberto',
        'status',
        'forma_pagamento',
        'forma_recebimento',
        'conta_financeira_id',
        'categoria_id',
        'centro_custo_id',
        'fornecedor_id',
        'pedido_id',
        'parcelamento_id',
        'parcela_numero',
        'parcelas_total',
        'is_entrada',
        'tipo',
        'quantidade_parcelas',
        'intervalo_meses',
        'valor_total',
        'valor_entrada',
        'primeiro_vencimento',
        'observacoes',
    ];

    public function log(string $acao, Model $entidade, ?array $antes = null, ?array $depois = null): void
    {
        $usuarioId = auth()->id();

        $ip = null;
        $ua = null;

        if (!app()->runningInConsole()) {
            $req = request();
            $ip = $req?->ip();
            $ua = $req?->userAgent();
        }

        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'modulo' => 'financeiro',
            'acao' => $acao,
            'label' => 'Auditoria financeira',
            'message' => "Financeiro: {$acao}",
            'actor_id' => $usuarioId,
            'entity_type' => get_class($entidade),
            'entity_id' => (int) $entidade->getKey(),
            'ip' => $ip,
            'user_agent' => $ua,
            'context_json' => [
                'antes' => $this->truncateJson($antes),
                'depois' => $this->truncateJson($depois),
            ],
            'source_system' => 'estoque',
            'source_kind' => 'business_event',
            'retention_days' => 365,
        ], $this->mudancasFinanceiras($antes, $depois));
    }

    private function truncateJson(?array $data, int $max = 20000): ?array
    {
        if ($data === null) return null;

        $json = json_encode($data);
        if ($json === false) return ['__error' => 'json_encode_failed'];

        if (strlen($json) <= $max) return $data;

        return [
            '__truncated' => true,
            '__size' => strlen($json),
        ];
    }

    /**
     * @return array<int,array{campo:string,old:mixed,new:mixed,value_type?:string}>
     */
    private function mudancasFinanceiras(?array $antes, ?array $depois): array
    {
        $antes = $this->normalizarOrigem($antes);
        $depois = $this->normalizarOrigem($depois);

        $old = [];
        $new = [];
        foreach (self::FINANCEIRO_AUDIT_FIELDS as $field) {
            if (array_key_exists($field, $antes)) {
                $old[$field] = $antes[$field];
            }
            if (array_key_exists($field, $depois)) {
                $new[$field] = $depois[$field];
            }
        }

        return AuditoriaDiff::changes($old, $new);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizarOrigem(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        if (isset($data['parcelamento']) && is_array($data['parcelamento'])) {
            return $data['parcelamento'];
        }

        return $data;
    }
}
