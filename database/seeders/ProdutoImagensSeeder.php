<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\ProdutoImagem;

/**
 * Seeder de imagens de produtos.
 *
 * - Baixa miniaturas do placehold.co e salva em storage/app/public/produtos.
 * - Persiste no banco apenas o NOME do arquivo (sem pastas).
 * - Garante que a extensão do arquivo corresponda ao Content-Type (ou força .jpg).
 */
class ProdutoImagensSeeder extends Seeder
{
    /**
     * @var bool Se true, força URL .jpg (recomendado para evitar incompatibilidades no Windows).
     */
    private bool $forceJpgUrl = true;

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        $now = Carbon::now();
        $produtos = DB::table('produtos')->select('id', 'nome')->get();

        // (Opcional) LIMPEZA antes de semear:
        // Storage::disk(ProdutoImagem::DISK)->deleteDirectory(ProdutoImagem::FOLDER);
        // Storage::disk(ProdutoImagem::DISK)->makeDirectory(ProdutoImagem::FOLDER);
        // DB::table('produto_imagens')->truncate();

        foreach ($produtos as $produto) {
            $qtdImagens = random_int(1, 3);
            $imagens = [];

            for ($i = 1; $i <= $qtdImagens; $i++) {
                // 1) Monte a URL
                // Forma robusta (forçar JPG na origem):
                $baseUrl = $this->forceJpgUrl
                    ? 'https://placehold.co/600x400.jpg'
                    : 'https://placehold.co/600x400'; // pode voltar PNG/WebP

                $url = $baseUrl . '?text=' . urlencode($produto->nome);

                // 2) Baixe com timeout/retry
                $response = Http::timeout(15)->retry(2, 200)->get($url);

                if (!$response->successful()) {
                    continue;
                }

                $binary = $response->body();

                // 3) Valide que é imagem (evita salvar HTML de erro como "imagem")
                $imageInfo = @getimagesizefromstring($binary);
                if ($imageInfo === false) {
                    continue;
                }

                // 4) Defina a extensão
                $contentType = strtolower($response->header('Content-Type') ?? '');
                $ext = $this->forceJpgUrl ? 'jpg' : $this->guessExtensionFromMime($contentType);

                // Fallback seguro
                if (!$ext) {
                    $ext = 'jpg';
                }

                // 5) Salve em disco
                $filename = sprintf(
                    '%d_%d_%s.%s',
                    $produto->id,
                    $i,
                    bin2hex(random_bytes(6)),
                    $ext
                );

                Storage::disk(ProdutoImagem::DISK)->put(
                    ProdutoImagem::FOLDER . '/' . $filename,
                    $binary,
                    ['visibility' => 'public']
                );

                // 6) Prepare registro para o banco (somente o NOME do arquivo)
                $imagens[] = [
                    'id_produto' => $produto->id,
                    'url'        => $filename,
                    'principal'  => $i === 1 ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($imagens)) {
                DB::table('produto_imagens')->insert($imagens);
            }
        }
    }

    /**
     * Mapeia Content-Type para extensão apropriada.
     *
     * @param string $mime Content-Type retornado pela requisição.
     * @return string|null
     */
    private function guessExtensionFromMime(string $mime): ?string
    {
        // Remove charset, se houver
        $mime = trim(explode(';', $mime)[0]);

        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png'               => 'png',
            'image/webp'              => 'webp',
            'image/gif'               => 'gif',
            default                   => null,
        };
    }
}
