<?php

namespace App\Repositories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repositório de acesso a dados de Categoria.
 */
class CategoriaRepository
{
    private const MYSQL_AI_CI = 'utf8mb4_0900_ai_ci';

    /**
     * Query base para listagem com árvore de subcategorias.
     *
     * @return Builder<Categoria>
     */
    public function queryIndex(): Builder
    {
        return Categoria::query()
            ->with('subcategorias.subcategorias')
            ->orderBy('nome');
    }

    /**
     * Aplica filtro de busca:
     * - ignora acentos/cedilha (via collation)
     * - remove expressões irrelevantes (stop phrases)
     * - busca por tokens (AND)
     *
     * @param Builder<Categoria> $query
     * @param string|null $search
     * @return Builder<Categoria>
     */
    public function applySearch(Builder $query, ?string $search): Builder
    {
        $tokens = $this->normalizeSearchToTokens($search);
        if (count($tokens) === 0) return $query;

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $collation = self::MYSQL_AI_CI;

            return $query->where(function (Builder $q) use ($tokens, $collation) {
                foreach ($tokens as $t) {
                    $q->whereRaw(
                        "`nome` COLLATE {$collation} LIKE ?",
                        ['%' . $t . '%']
                    );
                }
            });
        }

        // Fallback
        return $query->where(function (Builder $q) use ($tokens) {
            foreach ($tokens as $t) {
                $q->where('nome', 'like', '%' . $t . '%');
            }
        });
    }

    /**
     * Retorna categorias para o index.
     *
     * @param string|null $search
     * @return Collection<int, Categoria>
     */
    public function listar(?string $search): Collection
    {
        $query = $this->queryIndex();
        $query = $this->applySearch($query, $search);

        return $query->get(['id', 'nome', 'categoria_pai_id']);
    }

    /**
     * @param array{nome:string,descricao?:string|null,categoria_pai_id?:int|null} $data
     */
    public function create(array $data): Categoria
    {
        return Categoria::create($data);
    }

    /**
     * @param Categoria $categoria
     * @param array{nome?:string,descricao?:string|null,categoria_pai_id?:int|null} $data
     * @return \App\Models\Categoria
     */
    public function update(Categoria $categoria, array $data): Categoria
    {
        $categoria->update($data);
        return $categoria->refresh();
    }

    public function delete(Categoria $categoria): void
    {
        $categoria->delete();
    }

    /**
     * Remove acentos, lower, remove stop-phrases e vira tokens.
     *
     * @return list<string>
     */
    private function normalizeSearchToTokens(?string $search): array
    {
        $s = trim((string)$search);
        if ($s === '') return [];

        $s = mb_strtolower($s, 'UTF-8');

        // stop phrases (podemos crescer isso ao longo do tempo)
        $stopPhrases = [
            'mesa de centro',
            'mesa centro',
        ];

        foreach ($stopPhrases as $p) {
            $s = str_replace($p, ' ', $s);
        }

        // normaliza: mantém letras/números/espaços; remove pontuação
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);

        if ($s === '') return [];

        // tokens por espaço, remove curtos
        $parts = array_values(array_filter(explode(' ', $s), fn ($t) => mb_strlen($t) >= 2));

        // remove duplicados
        $unique = [];
        foreach ($parts as $t) {
            $unique[$t] = true;
        }

        return array_values(array_keys($unique));
    }

    private function guessMySqlAccentInsensitiveCollation(): string
    {
        // Ideal: usar collation do banco se já for ai_ci.
        // Se não, usar uma conhecida e comum.
        // Obs: não dá pra garantir 100% sem consultar o schema, então isso é um “best-effort”.
        return 'utf8mb4_0900_ai_ci';
    }
}
