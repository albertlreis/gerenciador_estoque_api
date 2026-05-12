<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\FormaPagamentoIndexRequest;
use App\Http\Requests\Financeiro\FormaPagamentoUpsertRequest;
use App\Http\Resources\FormaPagamentoResource;
use App\Models\FormaPagamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormaPagamentoController extends Controller
{
    public function index(FormaPagamentoIndexRequest $request): JsonResponse
    {
        $f = $request->validated();

        $items = FormaPagamento::query()
            ->select(['id', 'nome', 'slug', 'ativo'])
            ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn ($q) => $q->where('ativo', (bool) $f['ativo']))
            ->when(!empty($f['q']), function ($q) use ($f) {
                $term = trim((string) $f['q']);
                $q->where(fn ($w) => $w->where('nome', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%"));
            })
            ->orderBy('nome')
            ->get();

        return response()->json([
            'data' => FormaPagamentoResource::collection($items),
        ]);
    }

    public function store(FormaPagamentoUpsertRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
            $nome = trim((string) $data['nome']);
            $slug = $this->resolveSlug($nome, $data['slug'] ?? null, null);

            if ($this->nomeExisteNormalizado($nome)) {
                throw ValidationException::withMessages(['nome' => 'Já existe uma forma de pagamento com este nome.']);
            }

            $forma = FormaPagamento::create([
                'nome' => $nome,
                'slug' => $slug,
                'ativo' => $data['ativo'] ?? true,
            ]);

            return response()->json([
                'message' => 'Forma de pagamento criada com sucesso.',
                'data' => new FormaPagamentoResource($forma),
            ], 201);
        });
    }

    private function resolveSlug(string $nome, ?string $slugInformado, ?int $ignoreId): string
    {
        $base = $slugInformado ? Str::slug($slugInformado) : Str::slug($nome);
        $baseForSuffix = $base !== '' ? $base : 'forma-pagamento';
        $candidate = $baseForSuffix;
        $suffix = 2;

        while (
            FormaPagamento::query()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $baseForSuffix . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function nomeExisteNormalizado(string $nome): bool
    {
        $alvo = Str::lower(Str::ascii(trim($nome)));

        return FormaPagamento::query()
            ->get(['id', 'nome'])
            ->contains(fn ($item) => Str::lower(Str::ascii(trim((string)$item->nome))) === $alvo);
    }
}
