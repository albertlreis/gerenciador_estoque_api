<?php

namespace App\Support\Pdf;

use App\Models\Cliente;
use App\Models\ClienteEndereco;
use App\Models\Pedido;
use Illuminate\Validation\ValidationException;

class ClienteEnderecoPdf
{
    public static function resolverParaPedido(Pedido $pedido, mixed $clienteEnderecoId): ?ClienteEndereco
    {
        $pedido->loadMissing(['cliente.enderecos', 'cliente.enderecoPrincipal']);

        $cliente = $pedido->cliente;
        $enderecoId = self::normalizarId($clienteEnderecoId);

        if (! $cliente) {
            if ($enderecoId) {
                throw ValidationException::withMessages([
                    'cliente_endereco_id' => ['Este pedido nao possui cliente para selecionar endereco.'],
                ]);
            }

            return null;
        }

        $enderecos = $cliente->relationLoaded('enderecos')
            ? $cliente->enderecos
            : $cliente->enderecos()->get();

        if ($enderecoId) {
            $endereco = $enderecos->first(fn (ClienteEndereco $item) => (int) $item->id === $enderecoId);

            if (! $endereco) {
                throw ValidationException::withMessages([
                    'cliente_endereco_id' => ['Selecione um endereco cadastrado para o cliente deste pedido.'],
                ]);
            }

            return $endereco;
        }

        if ($enderecos->count() > 1) {
            throw ValidationException::withMessages([
                'cliente_endereco_id' => ['Selecione qual endereco do cliente sera usado no PDF.'],
            ]);
        }

        if ($enderecos->count() === 1) {
            return $enderecos->first();
        }

        return $cliente->enderecoPrincipal;
    }

    public static function paraResposta(?Cliente $cliente): array
    {
        if (! $cliente) {
            return [];
        }

        $cliente->loadMissing('enderecos');

        return $cliente->enderecos
            ->map(fn (ClienteEndereco $endereco) => self::formatarEndereco($endereco))
            ->values()
            ->all();
    }

    public static function formatarEndereco(?ClienteEndereco $endereco): ?array
    {
        if (! $endereco) {
            return null;
        }

        return [
            'id' => $endereco->id,
            'cep' => $endereco->cep,
            'endereco' => $endereco->endereco,
            'numero' => $endereco->numero,
            'complemento' => $endereco->complemento,
            'bairro' => $endereco->bairro,
            'cidade' => $endereco->cidade,
            'estado' => $endereco->estado,
            'principal' => (bool) $endereco->principal,
        ];
    }

    public static function textoEndereco(?ClienteEndereco $endereco): string
    {
        if (! $endereco) {
            return '';
        }

        $cidade = trim((string) ($endereco->cidade ?? ''));
        $estado = trim((string) ($endereco->estado ?? ''));
        $cidadeEstado = trim($cidade . ($cidade !== '' && $estado !== '' ? '/' : '') . $estado);
        $cep = trim((string) ($endereco->cep ?? ''));

        return trim(implode(' - ', array_filter([
            $endereco->endereco ?? null,
            $endereco->numero ?? null,
            $endereco->complemento ?? null,
            $endereco->bairro ?? null,
            $cidadeEstado !== '' ? $cidadeEstado : null,
            $cep !== '' ? 'CEP ' . $cep : null,
        ], fn ($valor) => trim((string) $valor) !== '')));
    }

    private static function normalizarId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $normalizado = (int) $id;

        return $normalizado > 0 ? $normalizado : null;
    }
}
