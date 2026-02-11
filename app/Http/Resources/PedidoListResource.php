<?php

namespace App\Http\Resources;

use App\Services\BusinessDayService;
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

        $agoraBelem = Carbon::now('America/Belem');
        $dataLimite = $this->data_limite_entrega ? Carbon::parse($this->data_limite_entrega) : null;
        $dataLimiteCalculada = null;

        // Fallback: calcula data limite quando estiver vazia (usa dias úteis)
        if (!$dataLimite && $this->data_pedido) {
            $prazo = (int) ($this->prazo_dias_uteis ?? config('orders.prazo_padrao_dias_uteis', 60));
            $dataPedido = $this->data_pedido instanceof Carbon
                ? $this->data_pedido
                : Carbon::parse($this->data_pedido);

            $dataLimite = app(BusinessDayService::class)->addBusinessDays(
                $dataPedido->copy()->timezone('America/Belem'),
                max(0, $prazo),
                config('holidays.default_uf', 'PA')
            );
            $dataLimiteCalculada = $dataLimite->toDateString();
        }

        // >>> Só calcula se o status atual conta para o prazo de entrega
        $diasUteisRestantes = null;
        $atrasadoEntrega = false;

        if ($dataLimite && $this->contaPrazoEntrega($statusAtualEnum)) {
            $diasUteisRestantes = $agoraBelem->diffInWeekdays($dataLimite, false);
            $atrasadoEntrega    = $agoraBelem->greaterThan($dataLimite);
        }

        return [
            'id'                     => $this->id,
            'numero_externo'         => $this->numero_externo,
            'data'                   => $this->data_pedido,
            'cliente'                => $this->cliente,
            'parceiro'               => $this->parceiro,
            'vendedor'               => $this->usuario,
            'data_ultimo_status'     => $dataUltimoStatus,
            'valor_total'            => $this->valor_total,

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
            // campo adicional para debug/compat (sem quebrar contrato)
            'data_limite_entrega_calculada' => $dataLimiteCalculada,
            'dias_uteis_restantes'   => $diasUteisRestantes, // null quando não se aplica
            'atrasado_entrega'       => $atrasadoEntrega,

            'observacoes'            => $this->observacoes,
            'tem_devolucao'          => $this->devolucoes->isNotEmpty(),
        ];
    }
}
