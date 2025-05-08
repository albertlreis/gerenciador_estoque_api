<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Validation\ValidationException;
use App\Helpers\CepHelper;
use App\Validators\DocumentoValidator;

class ClienteService
{
    public function validarDocumento(string $documento, string $tipo): bool
    {
        return $tipo === 'pf'
            ? DocumentoValidator::validarCPF($documento)
            : DocumentoValidator::validarCNPJ($documento);
    }

    public function documentoDuplicado(string $documento, ?int $ignorarId = null): bool
    {
        $doc = preg_replace('/\D/', '', $documento);
        $query = Cliente::where('documento', $doc);
        if ($ignorarId) $query->where('id', '!=', $ignorarId);
        return $query->exists();
    }

    public function preencherEnderecoViaCep(array $data): array
    {
        if (empty($data['cep'])) return $data;
        $cepInfo = CepHelper::buscarCep($data['cep']);
        if (!$cepInfo) throw ValidationException::withMessages(['cep' => 'CEP inválido ou não encontrado.']);

        $data['endereco'] = strip_tags($data['endereco'] ?? $cepInfo['logradouro'] ?? '');
        $data['bairro'] = strip_tags($data['bairro'] ?? $cepInfo['bairro'] ?? '');
        $data['cidade'] = strip_tags($data['cidade'] ?? $cepInfo['localidade'] ?? '');
        $data['estado'] = strip_tags($data['estado'] ?? $cepInfo['uf'] ?? '');

        return $data;
    }
}
