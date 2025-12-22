<?php

namespace App\Repositories;

use App\Models\ClienteEndereco;

class ClienteEnderecoRepository
{
    public function desmarcarTodosComoNaoPrincipal(int $clienteId): void
    {
        ClienteEndereco::where('cliente_id', $clienteId)->update(['principal' => false]);
    }

    public function upsertPorFingerprint(int $clienteId, string $fingerprint, array $payload): ClienteEndereco
    {
        return ClienteEndereco::updateOrCreate(
            ['cliente_id' => $clienteId, 'fingerprint' => $fingerprint],
            $payload + ['fingerprint' => $fingerprint]
        );
    }
}
