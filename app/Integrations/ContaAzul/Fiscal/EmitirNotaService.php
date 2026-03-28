<?php

namespace App\Integrations\ContaAzul\Fiscal;

/**
 * Contrato reservado para futuro emissor; implementação real pode ser outro provider.
 */
class EmitirNotaService
{
    /**
     * @param  array<string, mixed>  $dados
     */
    public function emitir(array $dados): void
    {
        throw new \RuntimeException('Emissão fiscal não configurada.');
    }
}
