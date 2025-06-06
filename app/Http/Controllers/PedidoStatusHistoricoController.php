<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use Illuminate\Http\JsonResponse;

class PedidoStatusHistoricoController extends Controller
{
    const STATUS_CRITICOS = [
        PedidoStatus::ENTREGA_CLIENTE,
        PedidoStatus::FINALIZADO,
    ];

    public function historico(Pedido $pedido): JsonResponse
    {
        $usuario = auth()->user();

        $historico = $pedido->historicoStatus()
            ->with('usuario')
            ->orderBy('data_status')
            ->get()
            ->map(function ($item, $index) use ($pedido, $usuario) {
                $statusEnum = PedidoStatus::tryFrom($item->getRawOriginal('status'));
                $statusString = $statusEnum?->value ?? (string) $item->status;

                return [
                    'id' => $item->id,
                    'status' => $statusString,
                    'label' => $statusEnum?->label() ?? ucfirst(str_replace('_', ' ', $statusString)),
                    'icone' => self::iconePorStatus($statusString),
                    'cor' => self::corPorStatus($statusString),
                    'data_status' => $item->getAttribute('data_status'),
                    'observacoes' => $item->observacoes,
                    'usuario' => $item->usuario?->name,
                    'isUltimo' => $index === $pedido->historicoStatus->count() - 1,
                    'podeRemover' => !in_array($statusEnum, self::STATUS_CRITICOS) || $usuario->can('remover-status-critico'),
                ];
            });

        logAuditoria('pedido_status', "Histórico de status visualizado para Pedido #$pedido->id.", [
            'acao' => 'visualizacao',
            'nivel' => 'info',
            'pedido_id' => $pedido->id,
            'quantidade_registros' => $historico->count(),
        ], $pedido);

        return response()->json($historico);
    }

    public function cancelarStatus(PedidoStatusHistorico $statusHistorico): JsonResponse
    {
        $pedido = $statusHistorico->pedido;

        $statusCancelado = $statusHistorico->status;
        $dataStatus = $statusHistorico->data_status;

        $statusHistorico->delete();

        logAuditoria('pedido_status', "Status '$statusCancelado' removido do Pedido #$pedido->id.", [
            'acao' => 'cancelamento',
            'nivel' => 'warn',
            'status_cancelado' => $statusCancelado,
            'data_status' => $dataStatus,
        ], $pedido);

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
