<?php

namespace App\Http\Controllers;

use App\Helpers\PedidoHelper;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PedidoStatusHistoricoController extends Controller
{
    public function historico(Pedido $pedido): JsonResponse
    {
        $historico = $pedido->historicoStatus()
            ->with('usuario')
            ->orderBy('data_status')
            ->get();

        return response()->json($historico);
    }

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
}
