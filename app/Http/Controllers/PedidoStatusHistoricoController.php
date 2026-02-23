<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Helpers\ConfiguracaoHelper;
use App\Helpers\PedidoHelper;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoStatusHistoricoController extends Controller
{
    private const STATUS_CRITICOS = [
        PedidoStatus::ENTREGA_CLIENTE,
        PedidoStatus::FINALIZADO,
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function fluxoStatus(Pedido $pedido): JsonResponse
    {
        $fluxo = PedidoHelper::fluxoPorTipo($pedido);
        return response()->json(array_map(fn ($status) => $status->value, $fluxo));
    }

    public function historico(Pedido $pedido): JsonResponse
    {
        $usuario = auth()->user();

        $historico = $pedido->historicoStatus()
            ->with('usuario')
            ->get();

        $fluxo = PedidoHelper::fluxoPorTipo($pedido);
        $ordemMap = array_values(array_map(fn ($s) => $s->value, $fluxo));

        $datas = $historico->mapWithKeys(fn ($item) => [
            $item->getRawOriginal('status') => $item->data_status,
        ])->toArray();

        $prazos = ConfiguracaoHelper::prazos();
        $previsoes = PedidoHelper::previsoes($datas, $prazos);

        $historicoFormatado = $historico->map(function ($item) {
            $statusEnum = PedidoStatus::tryFrom($item->getRawOriginal('status'));
            $statusString = $statusEnum?->value ?? (string) $item->status;

            return [
                'id' => $item->id,
                'status' => $statusString,
                'label' => $statusEnum?->label() ?? ucfirst(str_replace('_', ' ', $statusString)),
                'icone' => self::iconePorStatus($statusString),
                'cor' => self::corPorStatus($statusString),
                'data_status' => $item->data_status,
                'observacoes' => $item->observacoes,
                'usuario' => $item->usuario?->nome,
                'ehPrevisao' => false,
            ];
        });

        $statusRegistrados = $historico->map(fn ($h) => (string) $h->getRawOriginal('status'))->unique();

        $previsoesFuturas = collect($previsoes)
            ->filter(fn ($data, $status) => $data && !$statusRegistrados->contains($status))
            ->map(fn ($data, $status) => [
                'id' => null,
                'status' => $status,
                'label' => PedidoStatus::tryFrom($status)?->label() ?? ucfirst(str_replace('_', ' ', $status)),
                'icone' => self::iconePorStatus($status),
                'cor' => '#adb5bd',
                'data_status' => $data,
                'observacoes' => 'Previsao automatica',
                'usuario' => null,
                'ehPrevisao' => true,
            ]);

        $todos = $historicoFormatado->merge($previsoesFuturas);

        $ordenado = $todos->sortByDesc(function ($item) use ($ordemMap) {
            return array_search($item['status'], $ordemMap, true) ?? -1;
        })->values();

        $primeiroRealIndex = $ordenado->search(fn ($item) => !$item['ehPrevisao']);

        $resultadoFinal = $ordenado->map(function ($item, $index) use ($usuario, $primeiroRealIndex) {
            $statusEnum = PedidoStatus::tryFrom($item['status']);
            $isUltimo = $index === $primeiroRealIndex;

            return [
                ...$item,
                'isUltimo' => $isUltimo,
                'podeRemover' => $isUltimo && (!in_array($statusEnum, self::STATUS_CRITICOS, true) || $usuario->can('remover-status-critico')),
            ];
        });

        return response()->json($resultadoFinal);
    }

    public function atualizarStatus(Request $request, Pedido $pedido): JsonResponse
    {
        $request->validate([
            'status' => 'required|string',
            'observacoes' => 'nullable|string',
        ]);

        $novoStatus = (string) $request->status;
        $fluxo = PedidoHelper::fluxoPorTipo($pedido);

        if ($pedido->historicoStatus()->where('status', $novoStatus)->exists()) {
            return response()->json(['message' => 'Este status ja foi registrado para o pedido.'], 422);
        }

        $statusFluxo = array_map(fn ($s) => $s->value, $fluxo);
        $posNovo = array_search($novoStatus, $statusFluxo, true);
        if ($posNovo === false) {
            return response()->json(['message' => 'Status invalido para esse pedido.'], 422);
        }

        $ultimoStatus = $pedido->historicoStatus()->latest('data_status')->first();
        $statusAnterior = $ultimoStatus?->status?->value ?? $ultimoStatus?->status;

        if ($ultimoStatus) {
            $statusAtual = $ultimoStatus->status?->value ?? $ultimoStatus->status;
            $posAtual = array_search($statusAtual, $statusFluxo, true);
            if ($posAtual !== false && $posNovo < $posAtual) {
                return response()->json(['message' => 'Nao e permitido regredir o status.'], 422);
            }
        }

        $pedido->historicoStatus()->create([
            'status' => $novoStatus,
            'observacoes' => $request->observacoes,
            'data_status' => now(),
            'usuario_id' => auth()->id(),
        ]);

        logAuditoria('pedido_status', "Status atualizado para '{$novoStatus}' no Pedido #{$pedido->id}.", [
            'acao' => 'atualizacao',
            'nivel' => 'info',
            'status_novo' => $novoStatus,
        ], $pedido);

        $this->auditLogger->logCustom(
            'Pedido',
            $pedido->id,
            'pedidos',
            'STATUS_CHANGE',
            "Status do pedido atualizado para {$novoStatus}",
            [
                'status' => [
                    'old' => $statusAnterior,
                    'new' => $novoStatus,
                ],
            ],
            [
                'status_novo' => $novoStatus,
            ]
        );

        return response()->json(['message' => 'Status atualizado com sucesso.']);
    }

    public function cancelarStatus(Request $request, Pedido $pedido, PedidoStatusHistorico $statusHistorico): JsonResponse
    {
        $pedido = $statusHistorico->pedido ?? $pedido;
        $statusCancelado = $statusHistorico->status?->value ?? $statusHistorico->status;
        $dataStatus = $statusHistorico->data_status;
        $motivo = $request->input('motivo');

        $statusHistorico->delete();

        logAuditoria('pedido_status', "Status '{$statusCancelado}' removido do Pedido #{$pedido->id}.", [
            'acao' => 'cancelamento',
            'nivel' => 'warn',
            'status_cancelado' => $statusCancelado,
            'data_status' => $dataStatus,
        ], $pedido);

        $this->auditLogger->logCustom(
            'Pedido',
            $pedido->id,
            'pedidos',
            'CANCEL',
            "Cancelamento de status do pedido: {$statusCancelado}",
            [
                'status' => [
                    'old' => $statusCancelado,
                    'new' => null,
                ],
            ],
            [
                'reason' => $motivo,
                'status_cancelado' => $statusCancelado,
                'data_status' => $dataStatus,
            ]
        );

        return response()->json(['message' => 'Status removido com sucesso.']);
    }

    private static function iconePorStatus(string $status): string
    {
        return match ($status) {
            'pedido_criado' => 'pi pi-file',
            'pedido_enviado_fabrica', 'envio_cliente' => 'pi pi-send',
            'nota_emitida' => 'pi pi-file-edit',
            'previsao_embarque_fabrica' => 'pi pi-clock',
            'embarque_fabrica' => 'pi pi-truck',
            'nota_recebida_compra' => 'pi pi-download',
            'entrega_estoque' => 'pi pi-box',
            'entrega_cliente' => 'pi pi-home',
            'consignado' => 'pi pi-share-alt',
            'devolucao_consignacao' => 'pi pi-refresh',
            'finalizado' => 'pi pi-check-circle',
            default => 'pi pi-info-circle',
        };
    }

    private static function corPorStatus(string $status): string
    {
        return match ($status) {
            'pedido_criado' => '#007bff',
            'pedido_enviado_fabrica' => '#0dcaf0',
            'nota_emitida' => '#20c997',
            'previsao_embarque_fabrica' => '#ffc107',
            'embarque_fabrica' => '#17a2b8',
            'nota_recebida_compra' => '#6610f2',
            'entrega_estoque' => '#6f42c1',
            'envio_cliente' => '#fd7e14',
            'entrega_cliente', 'finalizado' => '#198754',
            'consignado' => '#6c757d',
            'devolucao_consignacao' => '#dc3545',
            default => '#adb5bd',
        };
    }
}
