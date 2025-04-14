<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoImagem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdutoImagemController extends Controller
{
    public function index(Produto $produto)
    {
        return response()->json($produto->imagens);
    }

    public function store(Request $request, Produto $produto)
    {
        try {
            $request->validate([
                'image' => 'required|image|max:2048',
            ]);

            $file = $request->file('image');
            $extension = $file->getClientOriginalExtension();

            $hash = md5(uniqid(rand(), true));
            $filename = $hash . '.' . $extension;

            $folder = public_path('uploads/produtos');

            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            $file->move($folder, $filename);

            $imagem = ProdutoImagem::create([
                'id_produto' => $produto->id,
                'url'        => $filename,
                'principal'  => $request->boolean('principal'),
            ]);

            $imagem->append('url_completa');

            return response()->json($imagem, 201);

        } catch (Throwable $e) {
            // Captura informações do arquivo se houver
            $fileName = $request->hasFile('image') ? $request->file('image')->getClientOriginalName() : 'Arquivo não enviado';
            $fileSize = $request->hasFile('image') ? $request->file('image')->getSize() : 0;

            Log::error('Erro ao enviar imagem', [
                'fileName'   => $fileName,
                'fileSize'   => $fileSize,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => "Erro interno ao salvar imagem. Detalhes: {$e->getMessage()}"
            ], 500);
        }
    }


    public function show(Produto $produto, ProdutoImagem $imagem)
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }
        return response()->json($imagem);
    }

    public function update(Request $request, Produto $produto, ProdutoImagem $imagem)
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            'url'       => 'sometimes|required|string',
            'principal' => 'boolean',
        ]);

        $imagem->update($validated);
        return response()->json($imagem);
    }

    public function destroy(Produto $produto, ProdutoImagem $imagem)
    {
        // Obtém o valor da chave primária usando getKey()
        $imagemId = $imagem->getKey();

        // Busca a imagem pelo relacionamento do produto.
        $imagemFound = $produto->imagens()->find($imagemId);

        if (!$imagemFound) {
            return response()->json(['error' => "Imagem não pertence a este produto: {$imagemId}"], 404);
        }

        // Define o diretório das imagens
        $folder = public_path('uploads/produtos');
        // Constrói o caminho completo do arquivo
        $filePath = $folder . DIRECTORY_SEPARATOR . $imagemFound->url;

        // Verifica se o arquivo existe e tenta removê-lo
        if (file_exists($filePath)) {
            if (!unlink($filePath)) {
                Log::error("Falha ao excluir o arquivo: {$filePath}");
                return response()->json(['error' => 'Não foi possível remover o arquivo do diretório'], 500);
            }
        } else {
            Log::warning("Arquivo não encontrado para remoção: {$filePath}");
        }

        // Remove o registro do banco de dados
        $imagemFound->delete();
        return response()->json(null, 204);
    }
}
