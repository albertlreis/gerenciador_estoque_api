<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoImagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProdutoImagemController extends Controller
{
    /**
     * Lista as imagens de um produto.
     *
     * @param  Produto  $produto
     * @return JsonResponse
     */
    public function index(Produto $produto): JsonResponse
    {
        return response()->json($produto->imagens);
    }

    /**
     * Faz upload e cria o registro da imagem do produto.
     * - Salva no disco "public" em storage/app/public/produtos
     * - Persiste no banco apenas o NOME do arquivo (sem pasta)
     *
     * @param  Request  $request
     * @param  Produto  $produto
     * @return JsonResponse
     */
    public function store(Request $request, Produto $produto): JsonResponse
    {
        try {
            /** @var UploadedFile $file */
            $file = $request->file('image');
            $extension = $file->getClientOriginalExtension();

            // Nome único e curto, somente o nome com extensão (sem pastas)
            $filename = sprintf('%s.%s', bin2hex(random_bytes(16)), strtolower($extension));

            // Salva no storage/app/public/produtos/<filename>
            Storage::disk(ProdutoImagem::DISK)
                ->putFileAs(ProdutoImagem::FOLDER, $file, $filename, 'public');

            $imagem = ProdutoImagem::create([
                'id_produto' => $produto->id,
                'url'        => $filename, // <- somente nome do arquivo
                'principal'  => $request->boolean('principal'),
            ]);

            $imagem->append('url_completa');

            return response()->json($imagem, 201);
        } catch (Throwable $e) {
            $fileName = $request->hasFile('image') ? $request->file('image')->getClientOriginalName() : 'Arquivo não enviado';
            $fileSize = $request->hasFile('image') ? (int) $request->file('image')->getSize() : 0;

            Log::error('Erro ao enviar imagem', [
                'fileName' => $fileName,
                'fileSize' => $fileSize,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => "Erro interno ao salvar imagem. Detalhes: {$e->getMessage()}",
            ], 500);
        }
    }

    /**
     * Exibe uma imagem específica do produto.
     *
     * @param  Produto        $produto
     * @param  ProdutoImagem  $imagem
     * @return JsonResponse
     */
    public function show(Produto $produto, ProdutoImagem $imagem): JsonResponse
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }

        return response()->json($imagem);
    }

    /**
     * Atualiza dados de uma imagem (apenas metadados: principal).
     * **Não** atualiza o arquivo físico.
     *
     * @param  Request        $request
     * @param  Produto        $produto
     * @param  ProdutoImagem  $imagem
     * @return JsonResponse
     */
    public function update(Request $request, Produto $produto, ProdutoImagem $imagem): JsonResponse
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            // 'url' não deve ser atualizada manualmente; filename é controlado no upload
            'principal' => 'sometimes|boolean',
        ]);

        $imagem->update($validated);

        return response()->json($imagem);
    }

    /**
     * Remove a imagem do storage e o registro do banco.
     *
     * @param  Produto        $produto
     * @param  ProdutoImagem  $imagem
     * @return JsonResponse
     */
    public function destroy(Produto $produto, ProdutoImagem $imagem): JsonResponse
    {
        $imagemId   = $imagem->getKey();
        $imagemFound = $produto->imagens()->find($imagemId);

        if (!$imagemFound) {
            return response()->json(['error' => "Imagem não pertence a este produto: {$imagemId}"], 404);
        }

        // Exclui o arquivo físico, se existir
        $path = ProdutoImagem::FOLDER . '/' . $imagemFound->url;
        try {
            Storage::disk(ProdutoImagem::DISK)->delete($path);
        } catch (Throwable $e) {
            Log::error("Falha ao excluir o arquivo: {$path}", ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Não foi possível remover o arquivo do diretório'], 500);
        }

        // Remove o registro do banco
        $imagemFound->delete();

        return response()->json(null, 204);
    }

    /**
     * Define uma imagem como principal de um produto.
     *
     * @param  int $produto ID do produto
     * @param  int $imagem  ID da imagem
     * @return JsonResponse
     */
    public function definirPrincipal(int $produto, int $imagem): JsonResponse
    {
        $produto = Produto::findOrFail($produto);

        $imagemSelecionada = $produto->imagens()->where('id', $imagem)->firstOrFail();

        if ($imagemSelecionada->id_produto !== $produto->id) {
            abort(403, 'Imagem não pertence ao produto.');
        }

        // Zera o campo 'principal' de todas as imagens do produto
        $produto->imagens()->update(['principal' => false]);

        // Marca a imagem desejada como principal
        $imagemSelecionada->update(['principal' => true]);

        return response()->json([
            'message' => 'Imagem principal definida com sucesso.',
            'imagem'  => $imagemSelecionada,
        ]);
    }
}
