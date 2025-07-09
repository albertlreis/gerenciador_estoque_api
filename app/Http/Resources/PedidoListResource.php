<?php

namespace App\Http\Resources;

use App\Traits\PedidoStatusTrait;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para formatar o retorno de um pedido na listagem.
 *
 * @property int $id
 * @property string|null $numero_externo
 * @property string $data_pedido
 * @property object|null $cliente
 * @property object|null $parceiro
 * @property object|null $usuario
 * @property float $valor_total
 * @property string|null $observacoes
 * @property object|null $statusAtual
 */
class PedidoListResource extends JsonResource
{
    use PedidoStatusTrait;

    /**
     * Transforma o recurso em um array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $statusAtualEnum = $this->getStatusAtualEnum($this->resource);
        $dataUltimoStatus = $this->getDataUltimoStatus($this->resource);
        $proximoStatus = $this->getProximoStatus($this->resource);
        $previsao = $this->getPrevisaoProximoStatus($this->resource);
        $atrasado = $this->isAtrasado($this->resource);

        return [
            'id' => $this->id,
            'numero_externo' => $this->numero_externo,
            'data' => $this->data_pedido,
            'cliente' => $this->cliente,
            'parceiro' => $this->parceiro,
            'vendedor' => $this->usuario,
            'data_ultimo_status' => $dataUltimoStatus,
            'valor_total' => $this->valor_total,
            'status' => $statusAtualEnum?->value,
            'status_label' => $statusAtualEnum?->label(),
            'proximo_status' => $proximoStatus?->value,
            'proximo_status_label' => $proximoStatus?->label(),
            'previsao' => $previsao?->toDateString(),
            'atrasado' => $atrasado,
            'observacoes' => $this->observacoes,
        ];
    }
}
