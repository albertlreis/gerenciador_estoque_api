<?php

namespace App\Integrations\ContaAzul\Fiscal;

/**
 * Provider somente leitura: não assume emissão via API pública da Conta Azul.
 */
class ContaAzulFiscalReadOnlyProvider implements FiscalProviderInterface
{
    public function listarNotasImportadas(array $filtros = []): array
    {
        return [];
    }

    public function vincularNotaImportadaAoDocumento(string $notaExternaId, string $documentoLocalTipo, int $documentoLocalId): bool
    {
        return false;
    }
}
