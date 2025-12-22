<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Repositories\ClienteRepository;
use App\Repositories\ClienteEnderecoRepository;
use App\Validators\DocumentoValidator;

class ClienteService
{
    public function __construct(
        protected ClienteRepository $clientes,
        protected ClienteEnderecoRepository $enderecosRepo,
    ) {}

    public function listarClientes(array $filtros = []): Collection
    {
        return $this->clientes->listar($filtros);
    }

    public function validarDocumento(string $documento, string $tipo): bool
    {
        return $tipo === 'pf'
            ? DocumentoValidator::validarCPF($documento)
            : DocumentoValidator::validarCNPJ($documento);
    }

    public function documentoDuplicado(string $documento, ?int $ignorarId = null): bool
    {
        $doc = preg_replace('/\D/', '', $documento);
        if ($doc === '') return false;

        return $this->clientes->existsDocumento($doc, $ignorarId);
    }

    private function enderecoFingerprint(array $e): string
    {
        $norm = fn($v) => mb_strtolower(trim((string)($v ?? '')));

        $base = implode('|', [
            preg_replace('/\D/', '', $e['cep'] ?? ''),
            $norm($e['endereco'] ?? ''),
            $norm($e['numero'] ?? ''),
            $norm($e['complemento'] ?? ''),
            $norm($e['bairro'] ?? ''),
            $norm($e['cidade'] ?? ''),
            $norm($e['estado'] ?? ''),
        ]);

        return hash('sha256', $base);
    }

    private function sanitizeEndereco(array $e): array
    {
        return [
            'cep' => preg_replace('/\D/', '', (string)($e['cep'] ?? '')),
            'endereco' => strip_tags((string)($e['endereco'] ?? '')),
            'numero' => strip_tags((string)($e['numero'] ?? '')),
            'complemento' => strip_tags((string)($e['complemento'] ?? '')),
            'bairro' => strip_tags((string)($e['bairro'] ?? '')),
            'cidade' => strip_tags((string)($e['cidade'] ?? '')),
            'estado' => strip_tags((string)($e['estado'] ?? '')),
            'principal' => !empty($e['principal']),
        ];
    }

    /**
     * Regras:
     * - aceita array de endereços vindos da requisição
     * - NÃO busca CEP
     * - garante exatamente 1 principal (se nenhum, o primeiro vira principal; se vários, só o primeiro fica)
     */
    public function syncEnderecos(Cliente $cliente, array $enderecos): void
    {
        $enderecos = array_values(array_filter($enderecos, fn($e) => is_array($e)));

        if (count($enderecos) === 0) {
            return;
        }

        // garante exatamente 1 principal
        $hasPrincipal = collect($enderecos)->contains(fn($e) => !empty($e['principal']));
        if (!$hasPrincipal) {
            $enderecos[0]['principal'] = true;
        } else {
            $first = true;
            foreach ($enderecos as &$e) {
                if (!empty($e['principal'])) {
                    if ($first) $first = false;
                    else $e['principal'] = false;
                }
            }
        }

        // desmarca principal atual
        $this->enderecosRepo->desmarcarTodosComoNaoPrincipal($cliente->id);

        foreach ($enderecos as $e) {
            $san = $this->sanitizeEndereco($e);

            $fingerprint = $this->enderecoFingerprint($san);

            $payload = [
                'cep' => $san['cep'],
                'endereco' => $san['endereco'],
                'numero' => $san['numero'],
                'complemento' => $san['complemento'],
                'bairro' => $san['bairro'],
                'cidade' => $san['cidade'],
                'estado' => $san['estado'],
                'principal' => $san['principal'],
            ];

            $this->enderecosRepo->upsertPorFingerprint($cliente->id, $fingerprint, $payload);
        }
    }

    private function normalizeClienteData(array $data): array
    {
        if (isset($data['documento'])) {
            $data['documento'] = preg_replace('/\D/', '', (string)$data['documento']);
        }
        return $data;
    }

    public function criarClienteComEnderecos(array $data): Cliente
    {
        return DB::transaction(function () use ($data) {
            $data = $this->normalizeClienteData($data);

            $enderecos = $data['enderecos'] ?? [];
            unset($data['enderecos']);

            $cliente = Cliente::create($data);

            if (is_array($enderecos) && count($enderecos) > 0) {
                $this->syncEnderecos($cliente, $enderecos);
            }

            return $cliente->load(['enderecos']);
        });
    }

    public function atualizarClienteComEnderecos(Cliente $cliente, array $data): Cliente
    {
        return DB::transaction(function () use ($cliente, $data) {
            $data = $this->normalizeClienteData($data);

            $enderecos = $data['enderecos'] ?? null;
            unset($data['enderecos']);

            $cliente->update($data);

            if (is_array($enderecos)) {
                $this->syncEnderecos($cliente, $enderecos);
            }

            return $cliente->load(['enderecos']);
        });
    }
}
