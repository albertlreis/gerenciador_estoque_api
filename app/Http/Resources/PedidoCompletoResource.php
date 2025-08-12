<?php

namespace App\Http\Resources;

use App\Traits\PedidoStatusTrait;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoCompletoResource extends JsonResource
{
    use PedidoStatusTrait;

    public function toArray($request): array
    {
        $agoraBelem = Carbon::now('America/Belem');
        $dataLimite = $this->data_limite_entrega ? Carbon::parse($this->data_limite_entrega) : null;

        $statusAtualEnum = optional($this->statusAtual)->status;

        $diasUteisRestantes = null;
        $atrasadoEntrega = false;

        if ($dataLimite && $this->contaPrazoEntrega($statusAtualEnum)) {
            $diasUteisRestantes = $agoraBelem->diffInWeekdays($dataLimite, false);
            $atrasadoEntrega    = $agoraBelem->greaterThan($dataLimite);
        }

        return [
            'id'          => $this->id,
            'numero'      => $this->numero_externo,
            'data_pedido' => $this->data_pedido,
            'status'      => $statusAtualEnum,

            'cliente' => $this->cliente ? [
                'id'       => $this->cliente->id,
                'nome'     => $this->cliente->nome,
                'email'    => $this->cliente->email,
                'telefone' => $this->cliente->telefone,
            ] : null,

            'parceiro' => $this->parceiro ? [
                'id'   => $this->parceiro->id,
                'nome' => $this->parceiro->nome,
            ] : null,

            'usuario' => $this->usuario ? [
                'id'   => $this->usuario->id,
                'nome' => $this->usuario->nome,
            ] : null,

            'valor_total' => $this->valor_total,
            'observacoes' => $this->observacoes,

            'prazo_dias_uteis'     => $this->prazo_dias_uteis,
            'data_limite_entrega'  => $dataLimite?->toDateString(),
            'dias_uteis_restantes' => $diasUteisRestantes, // null quando não se aplica
            'atrasado_entrega'     => $atrasadoEntrega,

            'itens'      => PedidoItemResource::collection($this->itens),
            'historico'  => PedidoStatusResource::collection(
                $this->historicoStatus->sortBy('data_status')->values()
            ),
            'devolucoes' => PedidoDevolucaoResource::collection($this->whenLoaded('devolucoes')),
        ];
    }
}
