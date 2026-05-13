<?php

namespace App\Support\Financeiro;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogoFinanceiroNome
{
    public static function limpar(string $nome): string
    {
        $compactado = preg_replace('/\s+/', ' ', trim($nome)) ?? $nome;

        return trim($compactado);
    }

    public static function normalizar(string $nome): string
    {
        $ascii = Str::ascii(self::limpar($nome));
        $compactado = preg_replace('/\s+/', ' ', $ascii) ?? $ascii;

        return Str::lower(trim($compactado));
    }

    /**
     * @param class-string<Model> $modelClass
     * @param callable(Builder):void|null $scope
     */
    public static function primeiroDuplicado(
        string $modelClass,
        string $nome,
        ?int $ignoreId = null,
        ?callable $scope = null,
        string $campo = 'nome'
    ): ?Model {
        $alvo = self::normalizar($nome);

        /** @var Builder $query */
        $query = $modelClass::query()
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId));

        if ($scope) {
            $scope($query);
        }

        return $query
            ->get(['id', $campo, 'ativo'])
            ->first(fn (Model $item) => self::normalizar((string) $item->{$campo}) === $alvo);
    }
}
