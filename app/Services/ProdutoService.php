<?php

namespace App\Services;

use App\Models\Produto;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
            'ativo' => true,
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
     * Lista os produtos com filtros avançados e paginação.
     *
     * @param \Illuminate\Http\Request $request
     * @return LengthAwarePaginator
     */
    public function listarProdutosFiltrados($request): LengthAwarePaginator
    {
        $query = Produto::with([
            'categoria',
            'fornecedor',
            'variacoes.atributos',
            'variacoes.estoque',
            'variacoes.outlet',
            'variacoes.outlets',
            'imagemPrincipal'
        ]);

        if (!empty($request->nome)) {
            $query->where('nome', 'ILIKE', '%' . $request->nome . '%');
        }

        if (!empty($request->id_categoria)) {
            $ids = is_array($request->id_categoria)
                ? array_filter($request->id_categoria)
                : [$request->id_categoria];

            $query->whereIn('id_categoria', $ids);
        }

        if (!empty($request->fornecedor_id)) {
            $ids = is_array($request->fornecedor_id)
                ? array_filter($request->fornecedor_id)
                : [$request->fornecedor_id];

            $query->whereIn('id_fornecedor', $ids);
        }

        if (!is_null($request->ativo)) {
            $query->where('ativo', (bool) $request->ativo);
        }

        if (!is_null($request->is_outlet)) {
            if ($request->is_outlet) {
                $query->whereHas('variacoes.outlet', fn($q) => $q->where('quantidade_restante', '>', 0))
                    ->whereHas('variacoes.estoque', fn($q) => $q->where('quantidade', '>', 0));
            } else {
                $query->whereDoesntHave('variacoes.outlet', fn($q) => $q->where('quantidade_restante', '>', 0));
            }
        } elseif (!empty($request->estoque_status)) {
            if ($request->estoque_status === 'com_estoque') {
                $query->whereHas('variacoes.estoque', fn($q) => $q->where('quantidade', '>', 0));
            } elseif ($request->estoque_status === 'sem_estoque') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('variacoes.estoque')
                        ->orWhereHas('variacoes.estoque', fn($q2) => $q2->where('quantidade', '<=', 0));
                });
            }
        }

        if (!empty($request->atributos) && is_array($request->atributos)) {
            foreach ($request->atributos as $atributo => $valores) {
                if (!empty($valores)) {
                    $query->whereHas('variacoes.atributos', function ($q) use ($atributo, $valores) {
                        $q->where('atributo', $atributo)
                            ->whereIn('valor', is_array($valores) ? $valores : [$valores]);
                    });
                }
            }
        }

        $query->orderByDesc('created_at');

        return $query->paginate($request->get('per_page', 15));
    }
}
