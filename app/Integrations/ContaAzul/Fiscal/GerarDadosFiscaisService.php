<?php

namespace App\Integrations\ContaAzul\Fiscal;

/**
 * Ponto de extensão para montar dados fiscais a partir do domínio local (sem acoplar emissor).
 */
class GerarDadosFiscaisService
{
    /**
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    public function montar(array $contexto): array
    {
        return [];
    }
}
