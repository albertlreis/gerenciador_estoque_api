<?php

namespace App\Services;

use App\Helpers\AuthHelper;
use App\Traits\EstatisticaPedidoTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Serviço responsável por obter estatísticas de pedidos.
 */
class EstatisticaPedidoService
{
    use EstatisticaPedidoTrait;

    /**
     * Retorna estatísticas de pedidos agrupados por mês.
     *
     * @param Request $request
     * @return array
     */
    public function obterEstatisticas(Request $request): array
    {
        $intervalo = (int) $request->query('meses', 6);
        $usuarioId = AuthHelper::getUsuarioId();
        $temPermissaoTotal = AuthHelper::hasPermissao('pedidos.visualizar.todos');

        $cacheKey = $this->gerarCacheKey($intervalo, $temPermissaoTotal, $usuarioId);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($intervalo, $temPermissaoTotal, $usuarioId) {
            $meses = $this->gerarMesesReferencia($intervalo);
            $dados = $this->consultarDadosAgrupados($meses->first(), $temPermissaoTotal, $usuarioId);

            return [
                'labels' => $meses->map(fn($m) => $m->format('M/Y'))->toArray(),
                'quantidades' => $meses->map(fn($m) => (int) optional($dados->firstWhere('mes', $m->format('Y-m-01')))->total)->toArray(),
                'valores' => $meses->map(fn($m) => (float) optional($dados->firstWhere('mes', $m->format('Y-m-01')))->valor)->toArray(),
            ];
        });
    }
}
