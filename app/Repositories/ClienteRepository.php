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
        $nome = trim((string)($filtros['nome'] ?? ''));
        $documento = preg_replace('/\D/', '', (string)($filtros['documento'] ?? ''));

        $q = Cliente::query()->with(['enderecos']);

        if ($nome !== '') {
            $q->where('nome', 'like', '%' . $nome . '%');
        }

        if ($documento !== '') {
            // documento no banco já é "limpo"
            $q->where('documento', 'like', '%' . $documento . '%');
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
