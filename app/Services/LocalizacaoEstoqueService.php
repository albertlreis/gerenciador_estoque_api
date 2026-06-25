<?php

namespace App\Services;

use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\LocalizacaoEstoque;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalizacaoEstoqueService
{
    public function listarPorDeposito(Deposito $deposito, array $filtros = []): LengthAwarePaginator
    {
        $query = LocalizacaoEstoque::query()
            ->where('deposito_id', $deposito->id)
            ->withCount('estoques as ocupacao_itens')
            ->withSum('estoques as ocupacao_pecas', 'quantidade')
            ->orderBy('codigo_composto');

        if (array_key_exists('ativo', $filtros) && $filtros['ativo'] !== null && $filtros['ativo'] !== '') {
            $query->where('ativo', filter_var($filtros['ativo'], FILTER_VALIDATE_BOOLEAN));
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
                    ->orWhereRaw("observacoes LIKE ? ESCAPE '\\\\'", [$like]);
            });
        }

        $perPage = max(1, min(200, (int) ($filtros['per_page'] ?? 20)));

        return $query->paginate($perPage);
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

    public static function montarCodigo(?string $area, ?string $corredor, ?string $setor, ?string $coluna): ?string
    {
        $parts = [
            self::trimStatic($area),
            self::trimStatic($corredor),
            self::trimStatic($setor),
            self::trimStatic($coluna),
        ];

        if (count(array_filter($parts, fn ($part) => $part !== null)) === 0) {
            return null;
        }

        return implode('-', array_map(fn ($part) => $part ?? '-', $parts));
    }

    private function payload(Deposito $deposito, array $dados, ?LocalizacaoEstoque $localizacao = null): array
    {
        $area = $this->trimOrNull($dados['area'] ?? $localizacao?->area);
        $corredor = $this->trimOrNull($dados['corredor'] ?? $localizacao?->corredor);
        $setor = $this->trimOrNull($dados['setor'] ?? $localizacao?->setor);
        $coluna = $this->trimOrNull($dados['coluna'] ?? $localizacao?->coluna);
        $codigo = self::montarCodigo($area, $corredor, $setor, $coluna);

        if ($codigo === null) {
            throw ValidationException::withMessages([
                'localizacao' => ['Informe area, corredor, setor ou coluna.'],
            ]);
        }

        return [
            'deposito_id' => $deposito->id,
            'area' => $area,
            'corredor' => $corredor,
            'setor' => $setor,
            'coluna' => $coluna,
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
        $existe = LocalizacaoEstoque::query()
            ->where('deposito_id', $deposito->id)
            ->where('codigo_composto', $codigo)
            ->when($ignorarId, fn (Builder $q) => $q->whereKeyNot($ignorarId))
            ->exists();

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

        return $trimmed === '' ? null : $trimmed;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
