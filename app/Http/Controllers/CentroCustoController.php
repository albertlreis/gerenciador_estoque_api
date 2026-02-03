<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\CentroCustoIndexRequest;
use App\Http\Requests\Financeiro\CentroCustoUpsertRequest;
use App\Http\Resources\CentroCustoResource;
use App\Models\CentroCusto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CentroCustoController extends Controller
{
    public function index(CentroCustoIndexRequest $request): JsonResponse
    {
        $f = $request->validated();
        $tree = (bool)($f['tree'] ?? false);

        $q = CentroCusto::query()
            ->select(['id','nome','slug','centro_custo_pai_id','ordem','ativo','padrao','meta_json'])
            ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn($qq) => $qq->where('ativo', (bool)$f['ativo']))
            ->when(!empty($f['q']), function ($qq) use ($f) {
                $term = trim((string)$f['q']);
                $qq->where(fn($w) => $w->where('nome','like',"%{$term}%")->orWhere('slug','like',"%{$term}%"));
            })
            ->orderBy('ordem')
            ->orderBy('nome');

        $items = $q->get();

        if (!$tree) {
            return response()->json([
                'data' => CentroCustoResource::collection($items),
            ]);
        }

        // tree
        $arr = $items->map(fn($c) => (new CentroCustoResource($c))->toArray($request))->values()->all();

        $byId = [];
        foreach ($arr as $it) {
            $it['children'] = [];
            $byId[$it['id']] = $it;
        }

        $roots = [];
        foreach ($byId as $id => $it) {
            $paiId = $it['centro_custo_pai_id'];
            if ($paiId && isset($byId[$paiId])) {
                $byId[$paiId]['children'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
        }

        return response()->json(['data' => $roots]);
    }

    public function show(CentroCusto $centroCusto): JsonResponse
    {
        return response()->json([
            'data' => new CentroCustoResource($centroCusto),
        ]);
    }

    public function store(CentroCustoUpsertRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $request) {
            $slug = $this->resolveSlug(
                table: 'centros_custo',
                nome: $data['nome'],
                slugInformado: $data['slug'] ?? null,
                ignoreId: null
            );

            $paiId = $data['centro_custo_pai_id'] ?? null;
            if ($paiId) {
                $this->assertNoCycleCentroCusto(null, $paiId);
            }

            $centro = CentroCusto::create([
                ...$data,
                'slug' => $slug,
            ]);

            if (!empty($data['padrao'])) {
                CentroCusto::query()
                    ->whereKeyNot($centro->id)
                    ->update(['padrao' => false]);
            }

            $centro->refresh();

            return response()->json([
                'message' => 'Centro de custo criado com sucesso.',
                'data' => new CentroCustoResource($centro),
            ], 201);
        });
    }

    public function update(CentroCustoUpsertRequest $request, CentroCusto $centroCusto): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $centroCusto) {
            $slug = $this->resolveSlug(
                table: 'centros_custo',
                nome: $data['nome'] ?? $centroCusto->nome,
                slugInformado: $data['slug'] ?? null,
                ignoreId: (int)$centroCusto->id
            );

            $paiId = array_key_exists('centro_custo_pai_id', $data) ? $data['centro_custo_pai_id'] : $centroCusto->centro_custo_pai_id;
            if ($paiId) {
                $this->assertNoCycleCentroCusto((int)$centroCusto->id, (int)$paiId);
            }

            $centroCusto->fill([
                ...$data,
                'slug' => $slug,
            ]);

            $centroCusto->save();

            if (!empty($data['padrao'])) {
                CentroCusto::query()
                    ->whereKeyNot($centroCusto->id)
                    ->update(['padrao' => false]);
            }

            $centroCusto->refresh();

            return response()->json([
                'message' => 'Centro de custo atualizado com sucesso.',
                'data' => new CentroCustoResource($centroCusto),
            ]);
        });
    }

    public function destroy(CentroCusto $centroCusto): JsonResponse
    {
        if ($centroCusto->filhas()->exists()) {
            return response()->json([
                'message' => 'Não é possível remover: existe(m) centro(s) de custo filho(s) vinculado(s).',
            ], 409);
        }

        $centroCusto->delete();
        return response()->json(['message' => 'Centro de custo removido com sucesso.']);
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

    private function assertNoCycleCentroCusto(?int $currentId, int $parentId): void
    {
        if ($currentId !== null && $parentId === $currentId) {
            throw ValidationException::withMessages(['centro_custo_pai_id' => 'O pai não pode ser o próprio registro.']);
        }

        $seen = [];
        if ($currentId !== null) $seen[$currentId] = true;

        $pid = $parentId;
        while ($pid) {
            if (isset($seen[$pid])) {
                throw ValidationException::withMessages(['centro_custo_pai_id' => 'Hierarquia inválida (ciclo detectado).']);
            }
            $seen[$pid] = true;

            $pid = (int) (CentroCusto::query()->whereKey($pid)->value('centro_custo_pai_id') ?? 0);
            if ($pid === 0) break;
        }
    }
}
