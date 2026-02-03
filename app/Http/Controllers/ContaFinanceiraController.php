<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\ContaFinanceiraIndexRequest;
use App\Http\Requests\Financeiro\ContaFinanceiraUpsertRequest;
use App\Http\Resources\ContaFinanceiraOptionResource;
use App\Http\Resources\ContaFinanceiraResource;
use App\Models\ContaFinanceira;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContaFinanceiraController extends Controller
{
    public function index(ContaFinanceiraIndexRequest $request): JsonResponse
    {
        $f = $request->validated();

        $items = ContaFinanceira::query()
            ->select(['id','nome','slug','tipo','ativo','padrao','moeda'])
            ->when(!empty($f['tipo']), fn($q) => $q->where('tipo', $f['tipo']))
            ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn($q) => $q->where('ativo', (bool)$f['ativo']))
            ->when(!empty($f['q']), function ($q) use ($f) {
                $term = trim((string)$f['q']);
                $q->where(fn($w) => $w->where('nome','like',"%{$term}%")->orWhere('slug','like',"%{$term}%"));
            })
            ->orderByDesc('padrao')
            ->orderBy('nome')
            ->get();

        return response()->json([
            'data' => ContaFinanceiraOptionResource::collection($items),
        ]);
    }

    public function show(ContaFinanceira $contaFinanceira): JsonResponse
    {
        return response()->json([
            'data' => new ContaFinanceiraResource($contaFinanceira),
        ]);
    }

    public function store(ContaFinanceiraUpsertRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
            $slug = $this->resolveSlug(
                table: 'contas_financeiras',
                nome: $data['nome'],
                slugInformado: $data['slug'] ?? null,
                ignoreId: null
            );

            $conta = ContaFinanceira::create([
                ...$data,
                'slug' => $slug,
            ]);

            if (!empty($data['padrao'])) {
                ContaFinanceira::query()
                    ->where('tipo', $conta->tipo)
                    ->whereKeyNot($conta->id)
                    ->update(['padrao' => false]);
            }

            $conta->refresh();

            return response()->json([
                'message' => 'Conta financeira criada com sucesso.',
                'data' => new ContaFinanceiraResource($conta),
            ], 201);
        });
    }

    public function update(ContaFinanceiraUpsertRequest $request, ContaFinanceira $contaFinanceira): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $contaFinanceira) {
            $slug = $this->resolveSlug(
                table: 'contas_financeiras',
                nome: $data['nome'] ?? $contaFinanceira->nome,
                slugInformado: $data['slug'] ?? null,
                ignoreId: (int)$contaFinanceira->id
            );

            $contaFinanceira->fill([
                ...$data,
                'slug' => $slug,
            ]);

            $contaFinanceira->save();

            if (!empty($data['padrao'])) {
                ContaFinanceira::query()
                    ->where('tipo', $contaFinanceira->tipo)
                    ->whereKeyNot($contaFinanceira->id)
                    ->update(['padrao' => false]);
            }

            $contaFinanceira->refresh();

            return response()->json([
                'message' => 'Conta financeira atualizada com sucesso.',
                'data' => new ContaFinanceiraResource($contaFinanceira),
            ]);
        });
    }

    public function destroy(ContaFinanceira $contaFinanceira): JsonResponse
    {
        if ($contaFinanceira->lancamentos()->exists()) {
            return response()->json([
                'message' => 'Não é possível remover: existe(m) lançamento(s) vinculado(s).',
            ], 409);
        }

        try {
            $contaFinanceira->delete();
            return response()->json(['message' => 'Conta financeira removida com sucesso.']);
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
}
