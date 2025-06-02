<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Helpers\PedidoHelper;
use App\Models\Pedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PedidoStatusHistoricoController extends Controller
{
    public function previsoes(Pedido $pedido): JsonResponse
    {
        $historico = $pedido->historicoStatus()->get()->keyBy('status');

        $datas = $historico->mapWithKeys(fn($h) => [$h->status => $h->data_status])->toArray();

        $prazos = [
            'envio_fabrica' => (int) DB::table('configuracoes')->where('chave', 'prazo_envio_fabrica')->value('valor') ?? 5,
            'entrega_estoque' => (int) DB::table('configuracoes')->where('chave', 'prazo_entrega_estoque')->value('valor') ?? 7,
            'envio_cliente' => (int) DB::table('configuracoes')->where('chave', 'prazo_envio_cliente')->value('valor') ?? 3,
            'prazo_consignacao' => (int) DB::table('configuracoes')->where('chave', 'prazo_consignacao')->value('valor') ?? 15,
        ];

        $previsoes = PedidoHelper::previsoes($datas, $prazos);

        return response()->json($previsoes);
    }

    public function historico(Pedido $pedido): JsonResponse
    {
        $historico = $pedido->historicoStatus()
            ->with('usuario')
            ->orderBy('data_status')
            ->get()
            ->map(function ($item) {
                $statusEnum = $item->status instanceof PedidoStatus ? $item->status : PedidoStatus::tryFrom($item->status);
                $statusString = $item->status instanceof PedidoStatus ? $item->status->value : $item->status;

                return [
                    'status' => $statusString,
                    'label' => $statusEnum?->label() ?? ucfirst(str_replace('_', ' ', $statusString)),
                    'icone' => self::iconePorStatus($statusString),
                    'cor' => self::corPorStatus($statusString),
                    'data_status' => $item->data_status,
                    'observacoes' => $item->observacoes,
                    'usuario' => $item->usuario?->name,
                ];
            });

        return response()->json($historico);
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
