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
    /**
     * Cria um novo produto base.
     *
     * @param array $data
     * @return Produto
     */
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

    /**
     * Atualiza os dados do produto base.
     *
     * @param Produto $produto
     * @param array $data
     * @return Produto
     * @throws \Exception
     */
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

    /**
     * @throws \Exception
     */
    public function salvarManualConservacao(Produto $produto, UploadedFile $file): string
    {
        try {
            // Valida extensão (garantia extra além do FormRequest)
            if ($file->getClientOriginalExtension() !== 'pdf') {
                throw new Exception('Arquivo deve ser um PDF.');
            }

            $hash = md5(uniqid(rand(), true));
            $filename = $hash . '.' . $file->getClientOriginalExtension();
            $folder = public_path('uploads/manuais');

            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            // Remove o arquivo anterior se existir
            if (!empty($produto->manual_conservacao)) {
                $caminhoAntigo = public_path("uploads/manuais/$produto->manual_conservacao");
                if (file_exists($caminhoAntigo)) {
                    unlink($caminhoAntigo);
                }
            }

            // Move o novo arquivo
            $file->move($folder, $filename);

            // Atualiza o campo no banco
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

    /**
     * Lista ou busca produtos com filtros dinâmicos e controle contextual.
     *
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function listarProdutosFiltrados(Request $request): LengthAwarePaginator
    {
        $view           = $request->get('view', 'completa');
        $depositoId     = $request->input('deposito_id');
        $variacaoId     = $request->input('variacao_id');
        $comEstoque     = $request->boolean('com_estoque');
        $incluirEstoque = $request->boolean('incluir_estoque', in_array($view, ['completa', 'simplificada']));

        $with = [
            'categoria',
            'variacoes' => function ($q) use ($depositoId, $incluirEstoque) {
                $q->with(['atributos']);
                if ($incluirEstoque) {
                    $q->with(['estoque' => function ($e) use ($depositoId) {
                        if ($depositoId) {
                            $e->where('id_deposito', $depositoId);
                        }
                    }]);
                }
            },
        ];

        $query = Produto::with($with);

        if ($variacaoId) {
            $query->whereHas('variacoes', fn($q) => $q->where('id', $variacaoId));
        }

        if ($depositoId) {
            $query->whereHas('variacoes.estoque', fn($q) => $q->where('id_deposito', $depositoId));
        }

        // ========== BUSCA TEXTUAL FLEXÍVEL (acentos e cedilhas ignorados) ==========
        $term = $request->input('q') ?? $request->input('nome');
        if ($term) {
            // Normaliza e remove acentos/cedilhas localmente
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $term);
            $normalized = preg_replace('/[^A-Za-z0-9\s]/', '', $normalized);
            $normalized = trim(strtolower($normalized));

            $words = preg_split('/\s+/', $normalized);

            $query->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $like = "%{$w}%";

                    $q->where(function ($qq) use ($like) {
                        // 🔤 Usa COLLATE utf8mb4_0900_ai_ci para ignorar acentos, cedilha e case
                        $qq->whereRaw('LOWER(nome) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                            ->orWhereRaw('LOWER(descricao) COLLATE utf8mb4_0900_ai_ci LIKE ?', [$like])
                            ->orWhereHas('categoria', fn($qc) =>
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

        // ========== FILTROS DE ESTOQUE E DEPÓSITO ==========
        if ($depositoId) {
            $query->whereHas('variacoes.estoque', fn($qe) => $qe->where('id_deposito', $depositoId));
        }

        if ($comEstoque) {
            $query->whereHas('variacoes.estoque', fn($qe) => $qe->where('quantidade', '>', 0));
        }

        if ($status = $request->input('estoque_status')) {
            if ($status === 'com_estoque') {
                $query->whereHas('variacoes.estoque', fn($q) => $q->where('quantidade', '>', 0));
            } elseif ($status === 'sem_estoque') {
                $query->whereDoesntHave('variacoes.estoque')
                    ->orWhereHas('variacoes.estoque', fn($q) => $q->where('quantidade', '<=', 0));
            }
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
                $query->whereHas('variacoes.outlet', fn($q) => $q->where('quantidade_restante', '>', 0));
            } else {
                $query->whereDoesntHave('variacoes.outlet', fn($q) => $q->where('quantidade_restante', '>', 0));
            }
        }

        $query->orderByDesc('created_at');

        return $query->paginate($request->integer('per_page', 15));
    }

    /**
     * Retorna um produto completo com todas as relações necessárias para edição ou exibição detalhada.
     */
    public function obterProdutoCompleto(int $id, ?int $depositoId = null): Model|Collection|Builder|array|null
    {
        return Produto::with([
            'variacoes' => function ($q) use ($depositoId) {
                $q->with([
                    'atributos',
                    'outlets',
                    'outlets.motivo',
                    'outlets.formasPagamento.formaPagamento',
                    'estoque' => function ($e) use ($depositoId) {
                        if ($depositoId) {
                            $e->where('id_deposito', $depositoId);
                        }
                    },
                ]);
            },
            'fornecedor',
            'categoria',
            'imagens',
        ])->findOrFail($id);
    }
}
