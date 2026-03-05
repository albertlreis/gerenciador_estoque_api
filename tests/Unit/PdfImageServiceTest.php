<?php

namespace Tests\Unit;

use App\Services\PdfImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfImageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_converte_url_storage_em_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/teste.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII='));

        $service = app(PdfImageService::class);
        $dataUri = $service->toDataUri('/storage/produtos/teste.png');

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_converte_caminho_absoluto_de_container_em_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/teste-container.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII='));

        $service = app(PdfImageService::class);
        $dataUri = $service->toDataUri('/var/www/html/public/storage/produtos/teste-container.png');

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_retorna_null_para_arquivo_inexistente(): void
    {
        Storage::fake('public');

        $service = app(PdfImageService::class);

        $this->assertNull($service->toDataUri('/storage/produtos/inexistente.png'));
        $this->assertNull($service->toDataUri(''));
        $this->assertNull($service->toDataUri(null));
    }
}

