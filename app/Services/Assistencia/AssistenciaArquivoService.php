<?php

namespace App\Services\Assistencia;

use App\Models\AssistenciaArquivo;
use App\Models\AssistenciaChamado;
use App\Models\AssistenciaChamadoItem;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serviço de arquivos da Assistência.
 *
 * Regras:
 * - Campo "path" armazena APENAS o hash (sem pastas/arquivo).
 * - Todos os arquivos físicos vão para: "assistencias/chamados/{HASH}.{EXT}".
 * - Antes de salvar, verifica se já existe "{HASH}.{EXT}" no diretório; se existir, gera outro hash (salt) até ficar único.
 */
class AssistenciaArquivoService
{
    private Filesystem $disk;

    private const BASE_DIR = 'assistencias/chamados';

    public function __construct()
    {
        $this->disk = Storage::disk('public'); // disco público
    }

    /**
     * Salva arquivos para um chamado.
     *
     * @param  AssistenciaChamado $chamado
     * @param  UploadedFile[]     $files
     * @param  string|null        $tipo
     * @return Collection<AssistenciaArquivo>
     */
    public function storeForChamado(AssistenciaChamado $chamado, array $files, ?string $tipo = null): Collection
    {
        $dir = self::BASE_DIR;

        return collect($files)->map(function (UploadedFile $file) use ($chamado, $tipo, $dir) {
            $ext              = $this->extFromMime($file->getClientMimeType(), $file);
            [$hash, $fullPath] = $this->uniqueHashedPath($dir, $file, $ext);

            // salva fisicamente com o nome {HASH}.{EXT}
            $file->storeAs($dir, $hash . '.' . $ext, ['disk' => 'public']);

            return AssistenciaArquivo::query()->create([
                'chamado_id'     => $chamado->id,
                'item_id'        => null,
                'tipo'           => $tipo,
                'path'           => $hash, // apenas o hash
                'nome_original'  => $file->getClientOriginalName(),
                'tamanho'        => $file->getSize(),
                'mime'           => $file->getClientMimeType(),
            ]);
        });
    }

    /**
     * Salva arquivos para um item do chamado.
     *
     * @param  AssistenciaChamadoItem $item
     * @param  UploadedFile[]         $files
     * @param  string|null            $tipo
     * @return Collection<AssistenciaArquivo>
     */
    public function storeForItem(AssistenciaChamadoItem $item, array $files, ?string $tipo = null): Collection
    {
        $dir = self::BASE_DIR;

        return collect($files)->map(function (UploadedFile $file) use ($item, $tipo, $dir) {
            $ext              = $this->extFromMime($file->getClientMimeType(), $file);
            [$hash, $fullPath] = $this->uniqueHashedPath($dir, $file, $ext);

            $file->storeAs($dir, $hash . '.' . $ext, ['disk' => 'public']);

            return AssistenciaArquivo::query()->create([
                'chamado_id'     => (int) $item->chamado_id,
                'item_id'        => $item->id,
                'tipo'           => $tipo,
                'path'           => $hash, // apenas o hash
                'nome_original'  => $file->getClientOriginalName(),
                'tamanho'        => $file->getSize(),
                'mime'           => $file->getClientMimeType(),
            ]);
        });
    }

    /**
     * Stream do arquivo; se $asDownload=true, força download.
     */
    public function stream(AssistenciaArquivo $arquivo, bool $asDownload = false): StreamedResponse
    {
        $fullPath = $this->fullStoragePath($arquivo);
        abort_unless($this->disk->exists($fullPath), 404, 'Arquivo não encontrado no armazenamento.');

        $stream   = $this->disk->readStream($fullPath);
        $filename = $arquivo->nome_original ?: basename($fullPath);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type'        => $arquivo->mime,
            'Content-Length'      => (string) $arquivo->tamanho,
            'Content-Disposition' => sprintf('%s; filename="%s"',
                $asDownload ? 'attachment' : 'inline',
                addslashes($filename)
            ),
        ]);
    }

    /**
     * Remove o arquivo do storage e do banco.
     */
    public function delete(AssistenciaArquivo $arquivo): void
    {
        $fullPath = $this->fullStoragePath($arquivo);
        if ($this->disk->exists($fullPath)) {
            $this->disk->delete($fullPath);
        }
        $arquivo->delete();
    }

    // ----------------- Helpers de caminho/hash -----------------

    /**
     * Constrói o caminho completo no storage público para um registro.
     * Usa o "path" (hash) + ext no diretório único "assistencias/chamados".
     * Mantém compatibilidade para registros legados (quando "path" já incluía pastas).
     */
    public function fullStoragePath(AssistenciaArquivo $arquivo): string
    {
        // Compatibilidade retroativa: se path tiver '/', já é caminho completo antigo.
        if (str_contains((string) $arquivo->path, '/')) {
            return (string) $arquivo->path;
        }

        $ext = $this->extFromMime($arquivo->mime);
        return self::BASE_DIR . '/' . $arquivo->path . '.' . $ext;
    }

    /**
     * Gera um hash baseado no conteúdo do arquivo e garante unicidade no diretório.
     *
     * @return array{0:string,1:string} [hash, fullPath]
     */
    private function uniqueHashedPath(string $dir, UploadedFile $file, string $ext): array
    {
        // hash inicial baseado no conteúdo
        $hash = hash_file('sha1', $file->getRealPath());

        // se já existir, adiciona salt aleatório até ficar único
        $candidate = $dir . '/' . $hash . '.' . $ext;
        while ($this->disk->exists($candidate)) {
            $hash = hash('sha1', $hash . '|' . Str::uuid()->toString() . '|' . random_bytes(8));
            $candidate = $dir . '/' . $hash . '.' . $ext;
        }

        return [$hash, $candidate];
    }

    /**
     * Deduz a extensão a partir do MIME (ou, se preciso, pelo UploadedFile).
     */
    private function extFromMime(?string $mime, ?UploadedFile $file = null): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        if ($mime && isset($map[$mime])) {
            return $map[$mime];
        }

        // fallback: tenta pela extensão original enviada
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if ($ext) {
                return $ext;
            }
        }

        // último recurso
        return 'bin';
    }
}
