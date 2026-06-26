<?php

namespace App\Http\Controllers;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Http\Requests\Financeiro\ContaFinanceiraIndexRequest;
use App\Http\Requests\Financeiro\ContaFinanceiraUpsertRequest;
use App\Http\Resources\ContaFinanceiraOptionResource;
use App\Http\Resources\ContaFinanceiraResource;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContaFinanceiraController extends Controller
{
    public function index(ContaFinanceiraIndexRequest $request): JsonResponse
    {
        $f = $request->validated();

        $items = ContaFinanceira::query()
            ->select(['id','nome','slug','tipo','ativo','padrao','moeda','saldo_inicial','data_saldo_inicial','saldo_atual','saldo_atual_em'])
            ->when(!empty($f['tipo']), fn($q) => $q->where('tipo', $f['tipo']))
            ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn($q) => $q->where('ativo', (bool)$f['ativo']))
            ->when(!empty($f['q']), function ($q) use ($f) {
                $term = trim((string)$f['q']);
                $q->where(fn($w) => $w->where('nome','like',"%{$term}%")->orWhere('slug','like',"%{$term}%"));
            })
            ->orderByDesc('padrao')
            ->orderBy('nome')
            ->get()
            ->map(fn (ContaFinanceira $conta) => $this->withSaldoListagem($conta));

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

            $shouldResetImportedSaldo = $this->shouldResetImportedSaldo($contaFinanceira, $data);

            $contaFinanceira->fill([
                ...$data,
                'slug' => $slug,
            ]);

            if ($shouldResetImportedSaldo) {
                $contaFinanceira->forceFill([
                    'saldo_atual' => null,
                    'saldo_atual_em' => null,
                    'meta_json' => $this->withoutImportedSaldoMeta($contaFinanceira->meta_json),
                ]);
            }

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

    private function withSaldoListagem(ContaFinanceira $conta): ContaFinanceira
    {
        if ($conta->saldo_atual !== null) {
            $conta->setAttribute('saldo_base_origem', 'saldo_atual');
            return $conta;
        }

        $conta->setAttribute('saldo_atual', $this->saldoLivro($conta));
        $conta->setAttribute('saldo_base_origem', 'saldo_livro');

        return $conta;
    }

    private function saldoLivro(ContaFinanceira $conta): float
    {
        $inicio = $conta->data_saldo_inicial
            ? Carbon::parse($conta->data_saldo_inicial)->startOfDay()
            : Carbon::parse('1900-01-01')->startOfDay();

        $movimentos = LancamentoFinanceiro::query()
            ->where('conta_id', $conta->id)
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->where('data_movimento', '>=', $inicio)
            ->get()
            ->sum(fn (LancamentoFinanceiro $l) => $this->signedValue($l));

        return round((float) $conta->saldo_inicial + (float) $movimentos, 2);
    }

    private function signedValue(LancamentoFinanceiro $l): float
    {
        $tipo = $l->tipo?->value ?? (string) $l->tipo;
        $valor = (float) $l->valor;

        if ($tipo === LancamentoTipo::DESPESA->value) {
            return -$valor;
        }

        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string) $l->descricao), 'recebida') ? $valor : -$valor;
        }

        return $valor;
    }

    private function shouldResetImportedSaldo(ContaFinanceira $conta, array $data): bool
    {
        return $this->manualAnchorChanged($conta, $data)
            || $this->importedSaldoBeforeManualAnchor($conta, $data);
    }

    private function manualAnchorChanged(ContaFinanceira $conta, array $data): bool
    {
        $saldoChanged = array_key_exists('saldo_inicial', $data)
            && round((float) $conta->saldo_inicial, 2) !== round((float) $data['saldo_inicial'], 2);

        $dateChanged = array_key_exists('data_saldo_inicial', $data)
            && $conta->data_saldo_inicial?->format('Y-m-d') !== Carbon::parse($data['data_saldo_inicial'])->format('Y-m-d');

        return $saldoChanged || $dateChanged;
    }

    private function importedSaldoBeforeManualAnchor(ContaFinanceira $conta, array $data): bool
    {
        if ($conta->saldo_atual === null || $conta->saldo_atual_em === null || empty($data['data_saldo_inicial'])) {
            return false;
        }

        return Carbon::parse($conta->saldo_atual_em)->startOfDay()
            ->lt(Carbon::parse($data['data_saldo_inicial'])->startOfDay());
    }

    private function withoutImportedSaldoMeta(mixed $meta): ?array
    {
        if (!is_array($meta)) {
            return null;
        }

        unset(
            $meta['conta_azul_saldo'],
            $meta['saldo_conta_financeira'],
            $meta['saldos_contas_financeiras']
        );

        return $meta ?: null;
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
