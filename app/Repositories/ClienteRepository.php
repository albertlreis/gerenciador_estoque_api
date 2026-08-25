<?php

namespace App\Repositories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Collection;

class ClienteRepository
{
    /**
     * Lista clientes com filtros simples.
     * Filtros:
     * - nome: contém (like)
     * - documento: contém (somente dígitos)
     */
    public function listar(array $filtros = []): Collection
    {
        $nome = trim((string) ($filtros['nome'] ?? ''));
        $documento = preg_replace('/\D/', '', (string) ($filtros['documento'] ?? ''));

        $q = Cliente::query()->with(['enderecos']);

        if ($nome !== '') {
            $q->where('nome', 'like', '%'.$nome.'%');
        }

        if ($documento !== '') {
            // documento no banco já é "limpo"
            $q->where('documento', 'like', '%'.$documento.'%');
        }

        if (($filtros['dashboard_filtro'] ?? null) === 'com_compra_no_periodo') {
            $inicio = $filtros['data_inicio'] ?? null;
            $fim = $filtros['data_fim'] ?? null;
            $depositoId = $filtros['deposito_id'] ?? null;

            $q->whereHas('pedidos', function ($pedidos) use ($inicio, $fim, $depositoId) {
                if ($inicio) {
                    $pedidos->where('data_pedido', '>=', $inicio.' 00:00:00');
                }
                if ($fim) {
                    $pedidos->where('data_pedido', '<=', $fim.' 23:59:59');
                }
                if ($depositoId) {
                    $pedidos->whereHas('itens', fn ($item) => $item->where('id_deposito', $depositoId));
                }
            });
        }

        return $q->orderBy('nome')->get();
    }

    public function existsDocumento(string $documentoLimpo, ?int $ignorarId = null): bool
    {
        $q = Cliente::query()->where('documento', $documentoLimpo);

        if ($ignorarId) {
            $q->where('id', '!=', $ignorarId);
        }

        return $q->exists();
    }
}
