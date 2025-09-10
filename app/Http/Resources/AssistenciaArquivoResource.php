<?php

namespace App\Http\Resources;

use App\Models\AssistenciaArquivo as AssistenciaArquivoModel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin AssistenciaArquivoModel
 */
class AssistenciaArquivoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'chamado_id'    => $this->chamado_id,
            'item_id'       => $this->item_id,
            'tipo'          => $this->tipo,
            // Exibimos o hash salvo em "path" como nome técnico (conforme seu exemplo)
            'nome'          => (string) $this->path,
            'mime'          => $this->mime,
            'tamanho'       => (int) $this->tamanho,
            'url'           => $this->publicUrl(),
            'created_at'    => optional($this->created_at)->toISOString(),
        ];
    }

    /**
     * Monta a URL pública do arquivo, suportando:
     * - Legado: path com pastas (retorna como está);
     * - Novo: path = hash -> reconstrói em "assistencias/chamados/{hash}.{ext}".
     */
    private function publicUrl(): string
    {
        $disk = Storage::disk('public');
        $path = (string) $this->path;

        // Legado: se tiver '/', assume caminho completo já salvo
        if (str_contains($path, '/')) {
            return $disk->url($path);
        }

        // Novo: um único diretório para tudo
        $ext  = $this->guessExt($this->mime);
        $dir  = 'assistencias/chamados';
        return $disk->url($dir . '/' . $path . '.' . $ext);
    }

    /** Deduz extensão a partir do MIME. */
    private function guessExt(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
