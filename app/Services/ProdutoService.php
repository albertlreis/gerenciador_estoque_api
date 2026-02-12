<?php

namespace App\Services;

use App\Models\Produto;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdutoService
{
    public function store(array $data): Produto
    {
        $manualPath = null;

        if (isset($data['manual_conservacao']) && $data['manual_conservacao'] instanceof UploadedFile) {
            $manualPath = $data['manual_conservacao']->store('manuais');
        }

        return Produto::create([
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
    }

    public function update(Produto $produto, array $data): Produto
    {
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

        return $produto->refresh();
    }

    public function salvarManualConservacao(Produto $produto, UploadedFile $file): string
    {
        try {
            if ($file->getClientOriginalExtension() !== 'pdf') {
                throw new Exception('Arquivo deve ser um PDF.');
            }

            $hash = md5(uniqid(rand(), true));
            $filename = $hash . '.' . $file->getClientOriginalExtension();
            $folder = public_path('uploads/manuais');

            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            if (!empty($produto->manual_conservacao)) {
                $caminhoAntigo = public_path("uploads/manuais/$produto->manual_conservacao");
                if (file_exists($caminhoAntigo)) {
                    unlink($caminhoAntigo);
                }
            }

            $file->move($folder, $filename);

            $produto->manual_conservacao = $filename;
            $produto->save();

            return $filename;

        } catch (Throwable $e) {
            Log::error('Erro ao salvar manual de conservação', [
                'produto_id' => $produto->id,
                'file' => $file?->getClientOriginalName(),
                'size' => $file?->getSize(),
                'erro' => $e->getMessage(),
            ]);

            throw new Exception("Erro ao salvar o manual: " . $e->getMessage());
        }
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

        $with = [
            'categoria',
            'variacoes' => function ($q) use ($depositoId, $incluirEstoque, $status, $comEstoque) {
                // ✅ evita N+1 e mantém coerência do catálogo
                $q->with([
                    'atributos',
                    'outlets', // se você precisa do outlet no catálogo
                    'outlets.motivo',
                    'outlets.formasPagamento.formaPagamento',
                ]);

                if ($incluirEstoque) {
                    $q->with(['estoques' => function ($e) use ($depositoId, $status, $comEstoque) {
                        if ($depositoId) {
                            $e->where('id_deposito', $depositoId);
                        }

                        // Se o usuário pediu "com estoque", não faz sentido devolver linhas zeradas
                        if ($status === 'com_estoque' || $comEstoque) {
                            $e->where('quantidade', '>', 0);
                        }

                        $e->with(['deposito', 'localizacao']);
                    }]);
                }

                if ($status === 'com_estoque' || $comEstoque) {
                    $q->whereHas('estoques', function ($e) use ($depositoId) {
                        if ($depositoId) {
                            $e->where('id_deposito', $depositoId);
                        }
                        $e->where('quantidade', '>', 0);
                    });
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
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $term);
            $normalized = preg_replace('/[^A-Za-z0-9\s]/', '', $normalized);
            $normalized = trim(strtolower($normalized));
            $words = preg_split('/\s+/', $normalized);

            $query->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $like = "%{$w}%";

                    $q->where(function ($qq) use ($like) {
                        $qq->whereRaw('LOWER(nome) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                            ->orWhereRaw('LOWER(descricao) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                            ->orWhereHas('categoria', fn ($qc) =>
                            $qc->whereRaw('LOWER(nome) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                            )
                            ->orWhereHas('variacoes', function ($qv) use ($like) {
                                $qv->whereRaw('LOWER(referencia) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(codigo_barras) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                                    ->orWhereHas('atributos', function ($qa) use ($like) {
                                        $qa->whereRaw('LOWER(atributo) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                                            ->orWhereRaw('LOWER(valor) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like]);
                                    });
                            });
                    });
                }
            });
        }

        if ($referencia = $request->input('referencia')) {
            $ref = trim((string) $referencia);
            if ($ref !== '') {
                $query->whereHas('variacoes', function ($q) use ($ref) {
                    $q->where('referencia', 'like', "%{$ref}%");
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
            $query->whereDoesntHave('variacoes.estoques', $estoquePositivo);
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
}
