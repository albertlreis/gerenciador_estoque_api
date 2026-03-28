<?php

namespace App\Integrations\ContaAzul\Fiscal;

interface FiscalProviderInterface
{
    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    public function listarNotasImportadas(array $filtros = []): array;

    public function vincularNotaImportadaAoDocumento(string $notaExternaId, string $documentoLocalTipo, int $documentoLocalId): bool;
}
