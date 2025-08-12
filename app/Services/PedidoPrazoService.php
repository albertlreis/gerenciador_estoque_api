<?php

namespace App\Services;

use App\Models\Pedido;
use Carbon\Carbon;

/**
 * Serviço responsável por calcular e atualizar a data limite de entrega de um pedido.
 * - Parte sempre de data_pedido
 * - Considera dias úteis (finais de semana e feriados nacionais/estaduais)
 * - Usa prazo configurável (default em config/orders.php) ou o já salvo no pedido
 */
class PedidoPrazoService
{
    public function __construct(
        private readonly BusinessDayService $businessDayService
    ) {}

    /**
     * Define (ou redefine) a data_limite_entrega do pedido.
     *
     * @param  Pedido      $pedido          Pedido já persistido
     * @param  int|null    $prazoDiasUteis  Prazo a aplicar (se null usa o do pedido/config)
     * @param  string|null $uf              UF para feriados estaduais (default: config('holidays.default_uf'))
     * @return Pedido
     */
    public function definirDataLimite(Pedido $pedido, ?int $prazoDiasUteis = null, ?string $uf = null): Pedido
    {
        $prazo = $prazoDiasUteis
            ?? $pedido->prazo_dias_uteis
            ?? (int) config('orders.prazo_padrao_dias_uteis', 60);

        $ufRef = $uf ?? config('holidays.default_uf', 'PA');

        $dataPedido = $pedido->data_pedido instanceof \Carbon\Carbon
            ? $pedido->data_pedido
            : Carbon::parse($pedido->data_pedido);

        $dataLimite = $this->businessDayService->addBusinessDays(
            $dataPedido->copy()->timezone('America/Belem'),
            (int) $prazo,
            $ufRef
        );

        $pedido->prazo_dias_uteis    = (int) $prazo;
        $pedido->data_limite_entrega = $dataLimite->toDateString();
        $pedido->save();

        return $pedido;
    }
}
