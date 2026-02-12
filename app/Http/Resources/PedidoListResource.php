<?php

namespace App\Http\Resources;

use App\Enums\PedidoStatus;
use App\Traits\PedidoStatusTrait;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para formatar o retorno de um pedido na listagem.
 *
 * @property int $id
 * @property string|null $numero_externo
 * @property string $data_pedido
 * @property int $prazo_dias_uteis
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
        $statusAtualRaw   = $this->statusAtual?->getRawOriginal('status');
        $statusAtualValue = $statusAtualEnum?->value ?? (is_string($statusAtualRaw) ? $statusAtualRaw : null);

        $dataUltimoStatus = $this->getDataUltimoStatus($this->resource);
        $proximoStatus    = $this->getProximoStatus($this->resource);
        $previsao         = $this->getPrevisaoProximoStatus($this->resource);
        $atrasadoFluxo    = $this->isAtrasado($this->resource);

        $timezone = config('app.timezone', 'America/Belem');
        $hoje = CarbonImmutable::now($timezone)->startOfDay();

        $entregaPrevista = $this->resolveEntregaPrevista($timezone);
        $situacaoEntrega = $this->resolveSituacaoEntrega($statusAtualValue, $entregaPrevista, $timezone);

        $diasUteisRestantes = null;
        $atrasadoEntrega = $situacaoEntrega === 'Atrasado';
        $diasAtraso = 0;

        if ($entregaPrevista) {
            if ($situacaoEntrega === 'Atrasado') {
                $diasAtraso = $hoje->diffInDays($entregaPrevista);
            }

            if (!in_array($situacaoEntrega, ['Entregue', 'Cancelado'], true)) {
                $diasUteisRestantes = $hoje->diffInWeekdays($entregaPrevista, false);
            }
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

            'status'                 => $statusAtualValue,
            'status_label'           => $statusAtualEnum?->label(),
            'proximo_status'         => $proximoStatus?->value,
            'proximo_status_label'   => $proximoStatus?->label(),
            'previsao'               => $previsao?->toDateString(),
            'atrasado'               => $atrasadoFluxo,

            // Prazo/Entrega
            'prazo_dias_uteis'       => $this->prazo_dias_uteis,
            'data_limite_entrega'    => $entregaPrevista?->toDateString(),
            'entrega_prevista'       => $entregaPrevista?->toDateString(),
            'situacao_entrega'       => $situacaoEntrega,
            'dias_atraso'            => $diasAtraso,
            'dias_uteis_restantes'   => $diasUteisRestantes, // null quando nao se aplica
            'atrasado_entrega'       => $atrasadoEntrega,

            'observacoes'            => $this->observacoes,
            'tem_devolucao'          => $this->devolucoes->isNotEmpty(),
        ];
    }

    private function resolveEntregaPrevista(string $timezone): ?CarbonImmutable
    {
        if (!empty($this->data_limite_entrega)) {
            return CarbonImmutable::parse($this->data_limite_entrega, $timezone)->startOfDay();
        }

        $baseDate = $this->resolveDataBasePedido($timezone);
        if (!$baseDate) {
            return null;
        }

        $prazoDiasUteis = max(0, (int) ($this->prazo_dias_uteis ?? 0));

        return $baseDate->addWeekdays($prazoDiasUteis)->startOfDay();
    }

    private function resolveDataBasePedido(string $timezone): ?CarbonImmutable
    {
        $camposBase = ['data_pedido', 'data_emissao', 'created_at'];

        foreach ($camposBase as $campo) {
            $valor = data_get($this, $campo);
            if (!$valor) {
                continue;
            }

            if ($valor instanceof CarbonInterface) {
                return CarbonImmutable::instance($valor)->setTimezone($timezone)->startOfDay();
            }

            return CarbonImmutable::parse((string) $valor, $timezone)->startOfDay();
        }

        return null;
    }

    private function resolveSituacaoEntrega(?string $statusAtual, ?CarbonImmutable $entregaPrevista, string $timezone): ?string
    {
        if ($this->isStatusCancelado($statusAtual)) {
            return 'Cancelado';
        }

        if ($this->isStatusEntregue($statusAtual) || $this->resolveDataEntregaReal($timezone)) {
            return 'Entregue';
        }

        if (!$entregaPrevista) {
            return null;
        }

        $hoje = CarbonImmutable::now($timezone)->startOfDay();

        if ($hoje->gt($entregaPrevista)) {
            return 'Atrasado';
        }

        if ($hoje->equalTo($entregaPrevista)) {
            return 'Entrega hoje';
        }

        return 'No prazo';
    }

    private function resolveDataEntregaReal(string $timezone): ?CarbonImmutable
    {
        $camposEntrega = ['entregue_em', 'data_entrega_real', 'data_entrega'];

        foreach ($camposEntrega as $campo) {
            $valor = data_get($this, $campo);
            if (!$valor) {
                continue;
            }

            if ($valor instanceof CarbonInterface) {
                return CarbonImmutable::instance($valor)->setTimezone($timezone)->startOfDay();
            }

            return CarbonImmutable::parse((string) $valor, $timezone)->startOfDay();
        }

        return null;
    }

    private function isStatusCancelado(?string $statusAtual): bool
    {
        if (!$statusAtual) {
            return false;
        }

        $status = strtolower($statusAtual);

        return in_array($status, ['cancelado', 'cancelada'], true);
    }

    private function isStatusEntregue(?string $statusAtual): bool
    {
        if (!$statusAtual) {
            return false;
        }

        return in_array($statusAtual, [
            PedidoStatus::ENTREGA_CLIENTE->value,
            PedidoStatus::FINALIZADO->value,
            'entregue',
        ], true);
    }
}
