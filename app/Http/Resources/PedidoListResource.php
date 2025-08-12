<?php

namespace App\Http\Resources;

use App\Traits\PedidoStatusTrait;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para formatar o retorno de um pedido na listagem.
 *
 * @property int $id
 * @property string|null $numero_externo
 * @property string $data_pedido
 * @property string data_limite_entrega
 * @property int prazo_dias_uteis
 * @property object|null $cliente
 * @property object|null $parceiro
 * @property object|null $usuario
 * @property float $valor_total
 * @property string|null $observacoes
 * @property object|null $statusAtual
 * @property mixed $devolucoes
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
        $statusAtualEnum  = $this->getStatusAtualEnum($this->resource);
        $dataUltimoStatus = $this->getDataUltimoStatus($this->resource);
        $proximoStatus    = $this->getProximoStatus($this->resource);
        $previsao         = $this->getPrevisaoProximoStatus($this->resource);
        $atrasadoFluxo    = $this->isAtrasado($this->resource);

        // Datas
        $dataLimite   = $this->data_limite_entrega ? Carbon::parse($this->data_limite_entrega) : null;
        $agoraBelem   = Carbon::now('America/Belem');

        // Dias úteis restantes até a data limite (pode ser negativo)
        $diasUteisRestantes = null;
        if ($dataLimite) {
            // diffInWeekdays($to, $absolute = false) -> negativo se já passou
            $diasUteisRestantes = $agoraBelem->diffInWeekdays($dataLimite, false);
        }

        // Atraso de entrega (com base em data_limite_entrega)
        $atrasadoEntrega = $dataLimite && $agoraBelem->greaterThan($dataLimite);

        return [
            'id'                     => $this->id,
            'numero_externo'         => $this->numero_externo,
            'data'                   => $this->data_pedido,
            'cliente'                => $this->cliente,
            'parceiro'               => $this->parceiro,
            'vendedor'               => $this->usuario,
            'data_ultimo_status'     => $dataUltimoStatus,
            'valor_total'            => $this->valor_total,

            // Status (fluxo)
            'status'                 => $statusAtualEnum?->value,
            'status_label'           => $statusAtualEnum?->label(),
            'proximo_status'         => $proximoStatus?->value,
            'proximo_status_label'   => $proximoStatus?->label(),
            'previsao'               => $previsao?->toDateString(),
            'atrasado'               => $atrasadoFluxo,

            // Prazo/Entrega
            'prazo_dias_uteis'       => $this->prazo_dias_uteis,
            'data_limite_entrega'    => $dataLimite?->toDateString(),
            'entrega_prevista'       => $dataLimite?->format('Y-m-d'),
            'dias_uteis_restantes'   => $diasUteisRestantes,
            'atrasado_entrega'       => $atrasadoEntrega,

            'observacoes'            => $this->observacoes,
            'tem_devolucao'          => $this->devolucoes->isNotEmpty(),
        ];
    }
}
