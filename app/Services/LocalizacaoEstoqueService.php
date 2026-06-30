<?php

namespace App\Services;

use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\LocalizacaoEstoque;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalizacaoEstoqueService
{
    public function listarPorDeposito(Deposito $deposito, array $filtros = []): LengthAwarePaginator
    {
        $query = LocalizacaoEstoque::query()
            ->where('deposito_id', $deposito->id)
            ->withCount('estoques as ocupacao_itens')
            ->withSum('estoques as ocupacao_pecas', 'quantidade');

        if (array_key_exists('ativo', $filtros) && $filtros['ativo'] !== null && $filtros['ativo'] !== '') {
            $query->where('ativo', filter_var($filtros['ativo'], FILTER_VALIDATE_BOOLEAN));
        }

        $ocupacao = (string) ($filtros['ocupacao'] ?? 'todas');
        if ($ocupacao === 'ocupadas') {
            $query->has('estoques');
        } elseif ($ocupacao === 'vazias') {
            $query->doesntHave('estoques');
        }

        $busca = trim((string) ($filtros['q'] ?? ''));
        if ($busca !== '') {
            $like = '%' . $this->escapeLike($busca) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw("codigo_composto LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("area LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("corredor LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("setor LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("coluna LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("nivel LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("observacoes LIKE ? ESCAPE '\\\\'", [$like]);
            });
        }

        $perPage = max(1, min(200, (int) ($filtros['per_page'] ?? 20)));

        return $query
            ->orderBy('area')
            ->orderBy('corredor')
            ->orderBy('setor')
            ->orderBy('coluna')
            ->orderBy('nivel')
            ->orderBy('codigo_composto')
            ->paginate($perPage);
    }

    public function listarPendencias(array $filtros = []): LengthAwarePaginator
    {
        $query = Estoque::query()
            ->select('estoque.*')
            ->selectSub($this->reservasClientePorEstoqueSubquery(), 'quantidade_reservada_cliente')
            ->whereNull('localizacao_id')
            ->where(function (Builder $q) {
                $q->where('quantidade', '>', 0)
                    ->orWhereExists(function ($sub) {
                        $this->aplicarReservaClienteAberta($sub);
                    });
            })
            ->with(['deposito', 'variacao.produto', 'variacao.atributos']);

        $depositoId = $this->toNullablePositiveInt($filtros['deposito'] ?? null);
        if ($depositoId !== null) {
            $query->where('id_deposito', $depositoId);
        }

        $produto = trim((string) ($filtros['produto'] ?? ''));
        if ($produto !== '') {
            $like = '%' . $this->escapeLike($produto) . '%';
            $query->whereHas('variacao', function (Builder $variacaoQuery) use ($like) {
                $variacaoQuery
                    ->whereRaw("referencia LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("sku_interno LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("chave_variacao LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("codigo_barras LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereRaw("nome LIKE ? ESCAPE '\\\\'", [$like])
                    ->orWhereHas('produto', function (Builder $produtoQuery) use ($like) {
                        $produtoQuery
                            ->whereRaw("nome LIKE ? ESCAPE '\\\\'", [$like])
                            ->orWhereRaw("codigo_produto LIKE ? ESCAPE '\\\\'", [$like]);
                    });
            });
        }

        $perPage = max(1, min(200, (int) ($filtros['per_page'] ?? 20)));

        return $query
            ->orderBy('id_deposito')
            ->orderByDesc('quantidade')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function criar(Deposito $deposito, array $dados): LocalizacaoEstoque
    {
        $payload = $this->payload($deposito, $dados);
        $this->validarCodigoUnico($deposito, $payload['codigo_composto']);

        return LocalizacaoEstoque::create($payload);
    }

    public function atualizar(Deposito $deposito, LocalizacaoEstoque $localizacao, array $dados): LocalizacaoEstoque
    {
        $this->assertLocalizacaoDoDeposito($deposito, $localizacao);
        $payload = $this->payload($deposito, $dados, $localizacao);
        $this->validarCodigoUnico($deposito, $payload['codigo_composto'], $localizacao->id);

        $localizacao->update($payload);

        return $localizacao->refresh();
    }

    public function excluir(Deposito $deposito, LocalizacaoEstoque $localizacao): ?LocalizacaoEstoque
    {
        $this->assertLocalizacaoDoDeposito($deposito, $localizacao);

        if ($localizacao->estoques()->exists()) {
            $localizacao->update(['ativo' => false]);

            return $localizacao->refresh();
        }

        $localizacao->delete();

        return null;
    }

    public function atribuirAoEstoque(Estoque $estoque, ?int $localizacaoId): Estoque
    {
        return DB::transaction(function () use ($estoque, $localizacaoId) {
            if ($localizacaoId === null) {
                $estoque->update(['localizacao_id' => null]);

                return $estoque->refresh()->load(['deposito', 'localizacao']);
            }

            $localizacao = LocalizacaoEstoque::query()->findOrFail($localizacaoId);
            if ((int) $localizacao->deposito_id !== (int) $estoque->id_deposito) {
                throw ValidationException::withMessages([
                    'localizacao_id' => ['A localizacao deve pertencer ao mesmo deposito do estoque.'],
                ]);
            }

            if (!$localizacao->ativo) {
                throw ValidationException::withMessages([
                    'localizacao_id' => ['A localizacao selecionada esta inativa.'],
                ]);
            }

            $estoque->update(['localizacao_id' => $localizacao->id]);

            return $estoque->refresh()->load(['deposito', 'localizacao']);
        });
    }

    /**
     * @param array<int, mixed> $estoqueIds
     * @return array{atualizados:int,localizacao:LocalizacaoEstoque}
     */
    public function atribuirEstoquesEmMassa(array $estoqueIds, int $localizacaoId): array
    {
        $ids = collect($estoqueIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'estoque_ids' => ['Selecione ao menos um item de estoque.'],
            ]);
        }

        return DB::transaction(function () use ($ids, $localizacaoId) {
            $localizacao = LocalizacaoEstoque::query()->lockForUpdate()->findOrFail($localizacaoId);

            if (!$localizacao->ativo) {
                throw ValidationException::withMessages([
                    'localizacao_id' => ['A localizacao selecionada esta inativa.'],
                ]);
            }

            /** @var Collection<int, Estoque> $estoques */
            $estoques = Estoque::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();

            if ($estoques->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'estoque_ids' => ['Um ou mais itens de estoque nao foram encontrados.'],
                ]);
            }

            $depositos = $estoques->pluck('id_deposito')->unique()->values();
            if ($depositos->count() !== 1 || (int) $depositos->first() !== (int) $localizacao->deposito_id) {
                throw ValidationException::withMessages([
                    'localizacao_id' => ['Todos os itens devem pertencer ao mesmo deposito da localizacao.'],
                ]);
            }

            Estoque::query()
                ->whereIn('id', $ids->all())
                ->update([
                    'localizacao_id' => $localizacao->id,
                    'updated_at' => now(),
                ]);

            return [
                'atualizados' => $ids->count(),
                'localizacao' => $localizacao->refresh(),
            ];
        });
    }

    public static function montarCodigo(
        ?string $area,
        ?string $corredor,
        ?string $setor,
        ?string $coluna,
        ?string $nivel = null
    ): ?string
    {
        $parts = [
            self::trimStatic($area),
            self::trimStatic($corredor),
            self::trimStatic($setor),
            self::trimStatic($coluna),
            self::trimStatic($nivel),
        ];

        $parts = array_values(array_filter($parts, fn ($part) => $part !== null));

        if (count($parts) === 0) {
            return null;
        }

        return implode('-', $parts);
    }

    private function payload(Deposito $deposito, array $dados, ?LocalizacaoEstoque $localizacao = null): array
    {
        $area = $this->trimOrNull($dados['area'] ?? $localizacao?->area);
        $corredor = $this->trimOrNull($dados['corredor'] ?? $localizacao?->corredor);
        $setor = $this->trimOrNull($dados['setor'] ?? $localizacao?->setor);
        $coluna = $this->trimOrNull($dados['coluna'] ?? $localizacao?->coluna);
        $nivel = $this->trimOrNull($dados['nivel'] ?? $localizacao?->nivel);
        $codigo = self::montarCodigo($area, $corredor, $setor, $coluna, $nivel);

        if ($codigo === null) {
            throw ValidationException::withMessages([
                'localizacao' => ['Informe area, corredor, setor, coluna ou nivel.'],
            ]);
        }

        return [
            'deposito_id' => $deposito->id,
            'area' => $area,
            'corredor' => $corredor,
            'setor' => $setor,
            'coluna' => $coluna,
            'nivel' => $nivel,
            'codigo_composto' => $codigo,
            'observacoes' => array_key_exists('observacoes', $dados)
                ? $this->trimOrNull($dados['observacoes'])
                : $localizacao?->observacoes,
            'ativo' => array_key_exists('ativo', $dados)
                ? filter_var($dados['ativo'], FILTER_VALIDATE_BOOLEAN)
                : ($localizacao?->ativo ?? true),
        ];
    }

    private function validarCodigoUnico(Deposito $deposito, string $codigo, ?int $ignorarId = null): void
    {
        $localizacoes = LocalizacaoEstoque::query()
            ->where('deposito_id', $deposito->id)
            ->when($ignorarId, fn (Builder $q) => $q->whereKeyNot($ignorarId))
            ->get(['id', 'area', 'corredor', 'setor', 'coluna', 'nivel', 'codigo_composto']);

        $existe = $localizacoes->contains(function (LocalizacaoEstoque $localizacao) use ($codigo) {
            $codigoExistente = self::montarCodigo(
                $localizacao->area,
                $localizacao->corredor,
                $localizacao->setor,
                $localizacao->coluna,
                $localizacao->nivel
            ) ?? self::limparCodigoExistente($localizacao->codigo_composto);

            return $codigoExistente === $codigo;
        });

        if ($existe) {
            throw ValidationException::withMessages([
                'codigo_composto' => ['Ja existe uma localizacao com este codigo neste deposito.'],
            ]);
        }
    }

    private function assertLocalizacaoDoDeposito(Deposito $deposito, LocalizacaoEstoque $localizacao): void
    {
        if ((int) $localizacao->deposito_id !== (int) $deposito->id) {
            abort(404);
        }
    }

    private function trimOrNull(mixed $value): ?string
    {
        return self::trimStatic($value);
    }

    private static function trimStatic(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' || preg_match('/^-+$/', $trimmed) ? null : $trimmed;
    }

    private static function limparCodigoExistente(mixed $codigo): ?string
    {
        $codigo = self::trimStatic($codigo);
        if ($codigo === null) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map(fn ($part) => self::trimStatic($part), explode('-', $codigo)),
            fn ($part) => $part !== null
        ));

        return empty($parts) ? null : implode('-', $parts);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function reservasClientePorEstoqueSubquery()
    {
        return DB::table('estoque_reservas as er')
            ->selectRaw('COALESCE(SUM(CASE WHEN er.quantidade > er.quantidade_consumida THEN er.quantidade - er.quantidade_consumida ELSE 0 END), 0)')
            ->whereColumn('er.id_variacao', 'estoque.id_variacao')
            ->whereColumn('er.id_deposito', 'estoque.id_deposito')
            ->whereNotNull('er.pedido_id')
            ->where('er.status', 'ativa')
            ->whereRaw('(er.quantidade - er.quantidade_consumida) > 0')
            ->where(function ($expiraQuery) {
                $expiraQuery->whereNull('er.data_expira')
                    ->orWhere('er.data_expira', '>', now());
            });
    }

    private function aplicarReservaClienteAberta($query): void
    {
        $query->selectRaw('1')
            ->from('estoque_reservas as er')
            ->whereColumn('er.id_variacao', 'estoque.id_variacao')
            ->whereColumn('er.id_deposito', 'estoque.id_deposito')
            ->whereNotNull('er.pedido_id')
            ->where('er.status', 'ativa')
            ->whereRaw('(er.quantidade - er.quantidade_consumida) > 0')
            ->where(function ($expiraQuery) {
                $expiraQuery->whereNull('er.data_expira')
                    ->orWhere('er.data_expira', '>', now());
            });
    }

    private function toNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
