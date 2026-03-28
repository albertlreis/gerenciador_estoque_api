<?php

namespace App\Integrations\ContaAzul\Fiscal;

class ConsultarStatusNotaService
{
    /**
     * @return array<string, mixed>
     */
    public function status(string $referenciaExterna): array
    {
        return ['referencia' => $referenciaExterna, 'status' => 'desconhecido'];
    }
}
