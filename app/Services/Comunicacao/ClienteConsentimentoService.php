<?php

namespace App\Services\Comunicacao;

use App\Models\Cliente;
use App\Models\ClienteComunicacaoConsentimento;
use App\Services\AuditoriaEventoService;

class ClienteConsentimentoService
{
    public function __construct(private readonly AuditoriaEventoService $auditoria) {}

    public function sincronizar(Cliente $cliente, array $decisoes): void
    {
        foreach ($decisoes as $decisao) {
            $canal = strtolower((string) ($decisao['canal'] ?? ''));
            if (! in_array($canal, ['sms', 'whatsapp'], true)) {
                continue;
            }

            $antes = $cliente->consentimentosComunicacao()->where('canal', $canal)->first();
            $atual = ClienteComunicacaoConsentimento::query()->updateOrCreate(
                ['cliente_id' => $cliente->id, 'canal' => $canal],
                [
                    'situacao' => $decisao['situacao'],
                    'origem' => trim((string) $decisao['origem']),
                    'decidido_em' => $decisao['decidido_em'],
                    'responsavel_id' => auth()->id(),
                    'referencia_evidencia' => $decisao['referencia_evidencia'] ?? null,
                ]
            );

            if (! $antes || $antes->situacao !== $atual->situacao || $antes->decidido_em?->ne($atual->decidido_em)) {
                $this->auditoria->registrar(
                    module: 'clientes',
                    action: $atual->situacao === 'concedido'
                        ? 'cliente.comunicacao.consentimento_concedido'
                        : 'cliente.comunicacao.consentimento_revogado',
                    label: $atual->situacao === 'concedido'
                        ? 'Consentimento de comunicação concedido'
                        : 'Consentimento de comunicação revogado',
                    auditable: $cliente,
                    metadata: [
                        'canal' => $canal,
                        'situacao_anterior' => $antes?->situacao,
                        'situacao_atual' => $atual->situacao,
                        'origem' => $atual->origem,
                        'decidido_em' => $atual->decidido_em?->toIso8601String(),
                    ]
                );
            }

            if ($atual->situacao === 'revogado') {
                \App\Models\ComunicacaoEventoSaida::query()
                    // Um registro em processamento já pode ter sido entregue ao provider.
                    // A revogação interrompe apenas itens que ainda não saíram do outbox.
                    ->where('status', 'pendente')
                    ->where('canal', $canal)
                    ->where('cliente_id', $cliente->id)
                    ->update([
                        'status' => 'ignorado',
                        'erro_codigo' => 'CONSENTIMENTO_REVOGADO',
                        'erro_mensagem' => 'Consentimento revogado antes do envio.',
                        'processado_em' => now(),
                    ]);
            }
        }
    }
}
