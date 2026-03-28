<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Models\Cliente;

class ContaAzulPessoaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(Cliente $cliente): array
    {
        return array_filter([
            'nome' => $cliente->nome,
            'email' => $cliente->email,
            'telefone' => $cliente->telefone,
            'tipoPessoa' => $this->guessTipoPessoa((string) $cliente->documento),
            'documento' => $cliente->documento,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function guessTipoPessoa(string $documento): string
    {
        $digits = preg_replace('/\D+/', '', $documento) ?? '';

        return strlen($digits) > 11 ? 'JURIDICA' : 'FISICA';
    }
}
