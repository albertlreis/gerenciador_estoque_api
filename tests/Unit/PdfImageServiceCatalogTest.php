<?php

namespace Tests\Unit;

use App\Services\PdfImageService;
use PHPUnit\Framework\TestCase;

class PdfImageServiceCatalogTest extends TestCase
{
    /**
     * @dataProvider catalogImageDimensionsProvider
     */
    public function test_normaliza_imagens_do_catalogo_para_640_por_360(int $width, int $height): void
    {
        $normalized = (new PdfImageService())->catalogCardDataUri(
            $this->createPngDataUri($width, $height)
        );

        $this->assertNotNull($normalized);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $normalized);

        $raw = base64_decode(substr($normalized, strlen('data:image/jpeg;base64,')));
        $dimensions = getimagesizefromstring($raw);

        $this->assertIsArray($dimensions);
        $this->assertSame(640, $dimensions[0]);
        $this->assertSame(360, $dimensions[1]);
    }

    public static function catalogImageDimensionsProvider(): array
    {
        return [
            'paisagem' => [1200, 400],
            'retrato' => [400, 1200],
            'quadrada' => [600, 600],
        ];
    }

    public function test_rejeita_fonte_nao_raster_na_normalizacao_do_catalogo(): void
    {
        $this->assertNull((new PdfImageService())->catalogCardDataUri(
            'data:image/svg+xml;base64,' . base64_encode('<svg></svg>')
        ));
    }

    private function createPngDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 210, 190, 160);
        $center = imagecolorallocate($image, 40, 90, 60);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle(
            $image,
            (int) floor($width * 0.3),
            (int) floor($height * 0.3),
            (int) ceil($width * 0.7),
            (int) ceil($height * 0.7),
            $center,
        );

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
