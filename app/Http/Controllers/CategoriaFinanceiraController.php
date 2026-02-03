<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\CategoriaFinanceiraIndexRequest;
use App\Http\Requests\Financeiro\CategoriaFinanceiraUpsertRequest;
use App\Http\Resources\CategoriaFinanceiraOptionResource;
use App\Http\Resources\CategoriaFinanceiraResource;
use App\Models\CategoriaFinanceira;
use App\Services\CategoriaFinanceiraCatalogoService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoriaFinanceiraController extends Controller
{
    public function __construct(private CategoriaFinanceiraCatalogoService $service) {}

    public function index(CategoriaFinanceiraIndexRequest $request): JsonResponse
    {
        $f = $request->validated();
        $tree = (bool)($f['tree'] ?? false);

        // Flat
        if (!$tree) {
            $items = CategoriaFinanceira::query()
                ->select(['id','nome','slug','tipo','ativo','padrao','categoria_pai_id','ordem','meta_json'])
                ->when(!empty($f['tipo']), fn($q) => $q->where('tipo', $f['tipo']))
                ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn($q) => $q->where('ativo', (bool)$f['ativo']))
                ->when(!empty($f['q']), function ($q) use ($f) {
                    $term = trim((string)$f['q']);
                    $q->where(fn($w) => $w->where('nome','like',"%{$term}%")->orWhere('slug','like',"%{$term}%"));
                })
                ->orderBy('ordem')->orderBy('nome')
                ->get();

            return response()->json([
                'data' => CategoriaFinanceiraOptionResource::collection($items),
            ]);
        }

        // Tree
        return response()->json([
            'data' => $this->service->listar($f, true),
        ]);
    }

    public function show(CategoriaFinanceira $categoriaFinanceira): JsonResponse
    {
        return response()->json([
            'data' => new CategoriaFinanceiraResource($categoriaFinanceira),
        ]);
    }

    public function store(CategoriaFinanceiraUpsertRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $request) {
            $slug = $this->resolveSlug(
                table: 'categorias_financeiras',
                nome: $data['nome'],
                slugInformado: $data['slug'] ?? null,
                ignoreId: null
            );

            $paiId = $data['categoria_pai_id'] ?? null;
            if ($paiId) {
                $this->assertNoCycleCategoria(null, (int)$paiId);
            }

            $cat = CategoriaFinanceira::create([
                ...$data,
                'slug' => $slug,
            ]);

            if (!empty($data['padrao'])) {
                CategoriaFinanceira::query()
                    ->where('tipo', $cat->tipo)
                    ->whereKeyNot($cat->id)
                    ->update(['padrao' => false]);
            }

            $cat->refresh();

            return response()->json([
                'message' => 'Categoria financeira criada com sucesso.',
                'data' => new CategoriaFinanceiraResource($cat),
            ], 201);
        });
    }

    public function update(CategoriaFinanceiraUpsertRequest $request, CategoriaFinanceira $categoriaFinanceira): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $categoriaFinanceira) {
            $slug = $this->resolveSlug(
                table: 'categorias_financeiras',
                nome: $data['nome'] ?? $categoriaFinanceira->nome,
                slugInformado: $data['slug'] ?? null,
                ignoreId: (int)$categoriaFinanceira->id
            );

            $paiId = array_key_exists('categoria_pai_id', $data) ? $data['categoria_pai_id'] : $categoriaFinanceira->categoria_pai_id;
            if ($paiId) {
                $this->assertNoCycleCategoria((int)$categoriaFinanceira->id, (int)$paiId);
            }

            $categoriaFinanceira->fill([
                ...$data,
                'slug' => $slug,
            ]);

            $categoriaFinanceira->save();

            if (!empty($data['padrao'])) {
                CategoriaFinanceira::query()
                    ->where('tipo', $categoriaFinanceira->tipo)
                    ->whereKeyNot($categoriaFinanceira->id)
                    ->update(['padrao' => false]);
            }

            $categoriaFinanceira->refresh();

            return response()->json([
                'message' => 'Categoria financeira atualizada com sucesso.',
                'data' => new CategoriaFinanceiraResource($categoriaFinanceira),
            ]);
        });
    }

    public function destroy(CategoriaFinanceira $categoriaFinanceira): JsonResponse
    {
        if ($categoriaFinanceira->filhas()->exists()) {
            return response()->json([
                'message' => 'Não é possível remover: existe(m) subcategoria(s) vinculada(s).',
            ], 409);
        }

        if ($categoriaFinanceira->lancamentos()->exists()) {
            return response()->json([
                'message' => 'Não é possível remover: existe(m) lançamento(s) vinculado(s).',
            ], 409);
        }

        try {
            $categoriaFinanceira->delete();
            return response()->json(['message' => 'Categoria financeira removida com sucesso.']);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Não é possível remover: o registro possui vínculos no sistema.',
            ], 409);
        }
    }

    private function resolveSlug(string $table, string $nome, ?string $slugInformado, ?int $ignoreId): string
    {
        $base = $slugInformado ? Str::slug($slugInformado) : Str::slug($nome);

        if ($slugInformado) {
            $exists = DB::table($table)
                ->where('slug', $base)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['slug' => 'Slug já está em uso.']);
            }
            return $base;
        }

        $candidate = $base ?: 'item';
        $i = 2;

        while (DB::table($table)
            ->where('slug', $candidate)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $candidate = "{$base}-{$i}";
            $i++;
        }

        return $candidate;
    }

    private function assertNoCycleCategoria(?int $currentId, int $parentId): void
    {
        if ($currentId !== null && $parentId === $currentId) {
            throw ValidationException::withMessages(['categoria_pai_id' => 'O pai não pode ser o próprio registro.']);
        }

        $seen = [];
        if ($currentId !== null) $seen[$currentId] = true;

        $pid = $parentId;
        while ($pid) {
            if (isset($seen[$pid])) {
                throw ValidationException::withMessages(['categoria_pai_id' => 'Hierarquia inválida (ciclo detectado).']);
            }
            $seen[$pid] = true;

            $pid = (int) (CategoriaFinanceira::query()->whereKey($pid)->value('categoria_pai_id') ?? 0);
            if ($pid === 0) break;
        }
    }
}
