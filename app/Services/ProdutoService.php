<?php

namespace App\Services;

use App\Models\Produto;
use App\Support\Audit\AuditLogger;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProdutoService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function store(array $data): Produto
    {
        $manualPath = null;

        if (isset($data['manual_conservacao']) && $data['manual_conservacao'] instanceof UploadedFile) {
            $manualPath = $this->armazenarManualConservacao($data['manual_conservacao']);
        }

        return DB::transaction(function () use ($data, $manualPath) {
            $produto = Produto::create([
                'nome' => $data['nome'],
                'descricao' => $data['descricao'] ?? null,
                'id_categoria' => $data['id_categoria'],
                'id_fornecedor' => $data['id_fornecedor'] ?? null,
                'altura' => $data['altura'] ?? null,
                'largura' => $data['largura'] ?? null,
                'profundidade' => $data['profundidade'] ?? null,
                'peso' => $data['peso'] ?? null,
                'manual_conservacao' => $manualPath,
                'ativo' => $data['ativo'] ?? true,
                'motivo_desativacao' => $data['motivo_desativacao'] ?? null,
                'estoque_minimo' => $data['estoque_minimo'] ?? null,
            ]);

            $this->auditLogger->logCreate(
                $produto,
                'catalogo',
                "Produto criado: {$produto->nome}"
            );

            return $produto;
        });
    }

    public function update(Produto $produto, array $data): Produto
    {
        return DB::transaction(function () use ($produto, $data) {
            $before = $produto->getAttributes();

            $updateData = [
                'nome' => $data['nome'],
                'descricao' => $data['descricao'] ?? null,
                'id_categoria' => $data['id_categoria'],
                'id_fornecedor' => $data['id_fornecedor'] ?? null,
                'altura' => $data['altura'] ?? null,
                'largura' => $data['largura'] ?? null,
                'profundidade' => $data['profundidade'] ?? null,
                'peso' => $data['peso'] ?? null,
                'ativo' => $data['ativo'] ?? true,
                'motivo_desativacao' => $data['motivo_desativacao'] ?? null,
                'estoque_minimo' => $data['estoque_minimo'] ?? null,
            ];

            if (isset($data['manual_conservacao']) && $data['manual_conservacao'] instanceof UploadedFile) {
                $this->salvarManualConservacao($produto, $data['manual_conservacao']);
            }

            $produto->update($updateData);
            $produto = $produto->refresh();
            $dirty = $this->diffDirty($before, $produto->getAttributes());

            $this->auditLogger->logUpdate(
                $produto,
                'catalogo',
                "Produto atualizado: {$produto->nome}",
                [
                    '__before' => $before,
                    '__dirty' => $dirty,
                ]
            );

            if (array_key_exists('ativo', $dirty)) {
                $this->auditLogger->logCustom(
                    'Produto',
                    $produto->id,
                    'catalogo',
                    'STATUS_CHANGE',
                    $produto->ativo ? 'Produto ativado' : 'Produto desativado',
                    [
                        'ativo' => [
                            'old' => Arr::get($before, 'ativo'),
                            'new' => $produto->ativo,
                        ],
                    ],
                    [
                        'motivo_desativacao' => $produto->motivo_desativacao,
                    ]
                );
            }

            return $produto;
        });
    }


    public function salvarManualConservacao(Produto $produto, UploadedFile $file): string
    {
        try {
            $path = $this->armazenarManualConservacao($file);
            $this->removerManualConservacao($produto);

            $produto->manual_conservacao = $path;
            $produto->save();

            return $path;
        } catch (Throwable $e) {
            Log::error('Erro ao salvar manual de conserva????o', [
                'produto_id' => $produto->id,
                'file' => $file?->getClientOriginalName(),
                'size' => $file?->getSize(),
                'erro' => $e->getMessage(),
            ]);

            throw new Exception("Erro ao salvar o manual: " . $e->getMessage());
        }
    }

    private function armazenarManualConservacao(UploadedFile $file): string
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw new Exception('Arquivo deve ser um PDF.');
        }

        $hash = md5(uniqid((string) random_int(1000, 9999), true));
        $filename = $hash . '.pdf';
        $folder = 'manuais';

        Storage::disk('public')->putFileAs($folder, $file, $filename, 'public');

        return $folder . '/' . $filename;
    }

    private function removerManualConservacao(Produto $produto): void
    {
        $manual = $produto->manual_conservacao;
        if (!$manual) {
            return;
        }

        $manual = trim((string) $manual);
        if ($manual === '') {
            return;
        }

        if (Str::startsWith($manual, ['http://', 'https://'])) {
            return;
        }

        if (Str::startsWith($manual, ['/storage/', 'storage/'])) {
            $relative = ltrim(Str::replaceFirst('/storage/', '', $manual), '/');
            Storage::disk('public')->delete($relative);
            return;
        }

        if (Str::startsWith($manual, ['/uploads/', 'uploads/'])) {
            $relative = ltrim(Str::replaceFirst('/uploads/', '', $manual), '/');
            $path = public_path($relative);
            if (file_exists($path)) {
                @unlink($path);
            }
            return;
        }

        if (Str::startsWith($manual, 'manuais/')) {
            Storage::disk('public')->delete($manual);
            return;
        }

        $legacyPath = public_path('uploads/manuais/' . $manual);
        if (file_exists($legacyPath)) {
            @unlink($legacyPath);
            return;
        }

        Storage::disk('public')->delete('manuais/' . $manual);
    }


    public function listarProdutosFiltrados(Request $request): LengthAwarePaginator
    {
        $view           = $request->get('view', 'completa');
        $depositoId     = $request->input('deposito_id');
        $variacaoId     = $request->input('variacao_id');
        $comEstoque     = $request->boolean('com_estoque');
        $status         = $request->input('estoque_status'); // com_estoque | sem_estoque | null
        $incluirEstoque = $request->boolean('incluir_estoque', in_array($view, ['completa', 'simplificada']));

        // Escopos reutilizáveis
        $estoqueNoDeposito = function ($q) use ($depositoId) {
            if ($depositoId) {
                $q->where('id_deposito', $depositoId);
            }
        };

        $estoquePositivo = function ($q) use ($depositoId) {
            if ($depositoId) {
                $q->where('id_deposito', $depositoId);
            }
            $q->where('quantidade', '>', 0);
        };

        $estoqueZerado = function ($q) use ($depositoId) {
            if ($depositoId) {
                $q->where('id_deposito', $depositoId);
            }
            $q->where('quantidade', '=', 0);
        };

        $with = [
            'categoria',
            'variacoes' => function ($q) use ($depositoId, $incluirEstoque) {
                // ✅ evita N+1 e mantém coerência do catálogo
                $q->with([
                    'atributos',
                    'imagem',
                    'outlets', // se você precisa do outlet no catálogo
                    'outlets.motivo',
                    'outlets.formasPagamento.formaPagamento',
                ]);

                if ($incluirEstoque) {
                    $q->with(['estoques' => function ($e) use ($depositoId) {
                        if ($depositoId) {
                            $e->where('id_deposito', $depositoId);
                        }
                        $e->with(['deposito', 'localizacao']);
                    }]);
                }
            },
        ];

        $query = Produto::with($with);

        if ($variacaoId) {
            $query->whereHas('variacoes', fn ($q) => $q->where('id', $variacaoId));
        }

        // ========== BUSCA TEXTUAL ==========
        $term = $request->input('q') ?? $request->input('nome');
        if ($term) {
            $normalized = trim(mb_strtolower((string) $term));
            $words = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));

            if (!empty($words)) {
                $query->where(function ($q) use ($words) {
                    foreach ($words as $w) {
                        $escaped = $this->escapeLike($w);
                        if ($escaped === '') {
                            continue;
                        }

                        $like = "%{$escaped}%";

                        $q->where(function ($qq) use ($like) {
                            $qq->whereRaw("LOWER(nome) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like])
                                ->orWhereRaw("LOWER(descricao) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like])
                                ->orWhereHas('categoria', fn ($qc) =>
                                $qc->whereRaw("LOWER(nome) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like])
                                )
                                ->orWhereHas('variacoes', function ($qv) use ($like) {
                                    $qv->whereRaw("LOWER(referencia) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like])
                                        ->orWhereRaw("LOWER(codigo_barras) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like])
                                        ->orWhereHas('atributos', function ($qa) use ($like) {
                                            $qa->whereRaw("LOWER(atributo) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like])
                                                ->orWhereRaw("LOWER(valor) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$like]);
                                        });
                                });
                        });
                    }
                });
            }
        }

        if ($referencia = $request->input('referencia')) {
            $ref = trim((string) $referencia);
            if ($ref !== '') {
                $likeRef = '%' . $this->escapeLike(mb_strtolower($ref)) . '%';
                $query->whereHas('variacoes', function ($q) use ($likeRef) {
                    $q->whereRaw("LOWER(referencia) COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'", [$likeRef]);
                });
            }
        }

        // ========== FILTROS DE DEPÓSITO / ESTOQUE ==========
        if ($depositoId) {
            // restringe ao depósito informado (mesmo se estoque 0, para telas que só querem “existência no depósito”)
            $query->whereHas('variacoes.estoques', $estoqueNoDeposito);
        }

        if ($comEstoque) {
            $query->whereHas('variacoes.estoques', $estoquePositivo);
        }

        if ($status === 'com_estoque') {
            $query->whereHas('variacoes.estoques', $estoquePositivo);

        } elseif ($status === 'sem_estoque') {
            // Regra de catálogo: produto entra quando existir ao menos uma variação zerada.
            $query->whereHas('variacoes.estoques', $estoqueZerado);
        }

        // ========== OUTROS FILTROS ==========
        if ($ids = $request->input('id_categoria')) {
            $query->whereIn('id_categoria', $ids);
        }

        if ($forn = $request->input('fornecedor_id')) {
            $query->whereIn('id_fornecedor', $forn);
        }

        if (!is_null($request->input('ativo'))) {
            $query->where('ativo', (bool) $request->input('ativo'));
        }

        if (!is_null($request->input('is_outlet'))) {
            if ($request->boolean('is_outlet')) {
                $query->whereHas('variacoes.outlet', fn ($q) => $q->where('quantidade_restante', '>', 0));
            } else {
                $query->whereDoesntHave('variacoes.outlet', fn ($q) => $q->where('quantidade_restante', '>', 0));
            }
        }

        $query->orderByDesc('created_at');

        return $query->paginate($request->integer('per_page', 15));
    }

    public function obterProdutoCompleto(int $id, ?int $depositoId = null): Model|Collection|Builder|array|null
    {
        return Produto::with([
            'variacoes' => function ($q) use ($depositoId) {
                $q->with([
                    'atributos',
                    'imagem',
                    'outlets',
                    'outlets.motivo',
                    'outlets.formasPagamento.formaPagamento',
                    'estoques' => function ($e) use ($depositoId) {
                        if ($depositoId) {
                            $e->where('id_deposito', $depositoId);
                        }
                        $e->with(['deposito', 'localizacao']);
                    },
                ]);
            },
            'fornecedor',
            'categoria',
            'imagens',
        ])->findOrFail($id);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function diffDirty(array $before, array $after): array
    {
        $dirty = [];
        foreach ($after as $field => $value) {
            $anterior = $before[$field] ?? null;
            if ((string) $anterior !== (string) $value) {
                $dirty[$field] = $value;
            }
        }

        return $dirty;
    }
}
