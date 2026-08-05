<?php

namespace App\Services\Comunicacao;

use App\Enums\ContaStatus;
use App\Models\Cliente;
use App\Models\ComunicacaoEventoSaida;
use App\Models\ComunicacaoJornada;
use App\Models\ContaReceber;
use App\Models\Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ComunicacaoOutboxService
{
    public function __construct(private readonly ComunicacaoApiClient $client) {}

    public function registrarPedidoStatus(Pedido $pedido, string $status): int
    {
        $pedido->loadMissing('cliente.consentimentosComunicacao');
        $cliente = $pedido->cliente;
        if (! $cliente) {
            return 0;
        }

        $jornadas = ComunicacaoJornada::query()
            ->where('tipo', 'pedido')
            ->where('ativo', true)
            ->whereHas('eventos', fn ($query) => $query->where('evento_codigo', $status))
            ->with(['canais' => fn ($query) => $query->where('ativo', true)])
            ->get();

        $variaveis = [
            'pedido' => [
                'id' => $pedido->id,
                'numero' => $pedido->numero_externo ?? $pedido->id,
                'status' => $status,
            ],
            'cliente' => ['nome' => $cliente->nome ?? ''],
        ];

        return $this->registrarParaJornadas($jornadas, $cliente, 'pedido', (int) $pedido->id, $status, $variaveis);
    }

    public function registrarCobrancaMarco(ContaReceber $conta, ComunicacaoJornada $jornada, int $marco): int
    {
        $conta->loadMissing(['cliente.consentimentosComunicacao', 'pedido.cliente.consentimentosComunicacao']);
        $cliente = $conta->cliente_id ? $conta->cliente : $conta->pedido?->cliente;
        if (! $cliente || ! in_array($conta->status, [ContaStatus::ABERTA, ContaStatus::PARCIAL], true)) {
            return 0;
        }

        $jornada->load(['canais' => fn ($query) => $query->where('ativo', true)]);
        $variaveis = [
            'cliente' => ['nome' => $cliente->nome ?? ''],
            'conta' => [
                'id' => $conta->id,
                'descricao' => $conta->descricao,
                'numero_documento' => $conta->numero_documento,
                'data_vencimento' => $conta->data_vencimento?->toDateString(),
                'valor' => $conta->valor_liquido,
                'marco' => $marco,
            ],
        ];

        return $this->registrarParaJornadas(
            collect([$jornada]),
            $cliente,
            'conta_receber',
            (int) $conta->id,
            'lembrete:'.$marco,
            $variaveis
        );
    }

    public function agendarCobrancasHoje(?Carbon $agora = null): int
    {
        $agora ??= now('America/Belem');
        $total = 0;
        $jornadas = ComunicacaoJornada::query()->where('tipo', 'cobranca')->where('ativo', true)->get();

        foreach ($jornadas as $jornada) {
            foreach ((array) data_get($jornada->agenda, 'marcos', []) as $marco) {
                $marco = (int) $marco;
                $vencimento = $agora->copy()->subDays($marco)->toDateString();
                ContaReceber::query()
                    ->whereDate('data_vencimento', $vencimento)
                    ->whereIn('status', [ContaStatus::ABERTA->value, ContaStatus::PARCIAL->value])
                    ->with(['cliente.consentimentosComunicacao', 'pedido.cliente.consentimentosComunicacao'])
                    ->chunkById(100, function ($contas) use (&$total, $jornada, $marco): void {
                        foreach ($contas as $conta) {
                            $total += $this->registrarCobrancaMarco($conta, $jornada, $marco);
                        }
                    });
            }
        }

        return $total;
    }

    public function processarPendentes(int $limite = 50): array
    {
        $resultado = ['enviados' => 0, 'falhos' => 0];
        $ids = ComunicacaoEventoSaida::query()
            ->where('status', 'pendente')
            ->where(fn ($query) => $query->whereNull('disponivel_em')->orWhere('disponivel_em', '<=', now()))
            ->orderBy('id')
            ->limit($limite)
            ->pluck('id');

        foreach ($ids as $id) {
            $evento = DB::transaction(function () use ($id): ?ComunicacaoEventoSaida {
                $evento = ComunicacaoEventoSaida::query()->lockForUpdate()->find($id);
                if (! $evento || $evento->status !== 'pendente') {
                    return null;
                }
                $evento->update(['status' => 'processando', 'tentativas' => $evento->tentativas + 1]);

                return $evento->fresh();
            });

            if (! $evento) {
                continue;
            }

            try {
                $this->client->enviar([
                    'canal' => $evento->canal,
                    'para' => (string) $evento->destinatario,
                    'template_code' => $evento->template_codigo,
                    'variaveis' => (array) $evento->variaveis,
                    'correlation_id' => $evento->correlation_id,
                    'external_id' => $evento->idempotency_key,
                    'client_reference' => "{$evento->origem_tipo}:{$evento->origem_id}",
                    'store_only' => ! $this->envioRealHabilitado($evento->canal),
                ]);
                $evento->update(['status' => 'enviado', 'processado_em' => now(), 'erro_codigo' => null, 'erro_mensagem' => null]);
                $resultado['enviados']++;
            } catch (Throwable) {
                $this->registrarFalha($evento);
                $resultado['falhos']++;
            }
        }

        return $resultado;
    }

    private function registrarParaJornadas($jornadas, Cliente $cliente, string $origemTipo, int $origemId, string $eventoCodigo, array $variaveis): int
    {
        $total = 0;
        foreach ($jornadas as $jornada) {
            foreach ($jornada->canais as $canal) {
                [$destinatario, $impedimento] = $this->destino($cliente, $canal->canal);
                $idempotency = implode(':', ['jornada', $jornada->id, $origemTipo, $origemId, $eventoCodigo, $canal->canal]);
                $registro = ComunicacaoEventoSaida::query()->firstOrCreate(
                    ['idempotency_key' => $idempotency],
                    [
                        'jornada_id' => $jornada->id,
                        'cliente_id' => $cliente->id,
                        'origem_tipo' => $origemTipo,
                        'origem_id' => $origemId,
                        'evento_codigo' => $eventoCodigo,
                        'canal' => $canal->canal,
                        'template_codigo' => $canal->template_codigo,
                        'destinatario' => $destinatario,
                        'variaveis' => $variaveis,
                        'correlation_id' => Str::uuid()->toString(),
                        'status' => $impedimento ? 'ignorado' : 'pendente',
                        'disponivel_em' => now(),
                        'processado_em' => $impedimento ? now() : null,
                        'erro_codigo' => $impedimento,
                        'erro_mensagem' => $impedimento ? 'Envio impedido antes do provider.' : null,
                    ]
                );
                if ($registro->wasRecentlyCreated) {
                    $total++;
                }
            }
        }

        return $total;
    }

    private function destino(Cliente $cliente, string $canal): array
    {
        if ($canal === 'email') {
            if ($cliente->bloqueia_email || ! filter_var($cliente->email, FILTER_VALIDATE_EMAIL)) {
                return [null, $cliente->bloqueia_email ? 'EMAIL_BLOQUEADO' : 'EMAIL_INVALIDO'];
            }

            return [strtolower((string) $cliente->email), null];
        }

        $consentimento = $cliente->consentimentosComunicacao->firstWhere('canal', $canal);
        if (! $consentimento || $consentimento->situacao !== 'concedido') {
            return [null, 'CONSENTIMENTO_AUSENTE'];
        }

        $telefone = $canal === 'whatsapp'
            ? ($cliente->whatsapp ?: $cliente->telefone)
            : ($cliente->telefone ?: $cliente->whatsapp);
        $normalizado = preg_replace('/\D+/', '', (string) $telefone) ?? '';

        return $normalizado !== '' ? [$normalizado, null] : [null, 'TELEFONE_INVALIDO'];
    }

    private function envioRealHabilitado(string $canal): bool
    {
        return (bool) config('comunicacao.real_send_enabled', false)
            && (bool) config("comunicacao.channels.{$canal}", false);
    }

    private function registrarFalha(ComunicacaoEventoSaida $evento): void
    {
        $tentativas = (int) $evento->tentativas;
        if ($tentativas >= 4) {
            $evento->update([
                'status' => 'falho',
                'processado_em' => now(),
                'erro_codigo' => 'COMMS_REQUEST_FAILED',
                'erro_mensagem' => 'Falha ao registrar solicitação no serviço de comunicação.',
            ]);

            return;
        }

        $backoff = [1 => 30, 2 => 120, 3 => 300][$tentativas] ?? 300;
        $evento->update([
            'status' => 'pendente',
            'disponivel_em' => now()->addSeconds($backoff),
            'erro_codigo' => 'COMMS_REQUEST_RETRY',
            'erro_mensagem' => 'Nova tentativa de integração agendada.',
        ]);
    }
}
