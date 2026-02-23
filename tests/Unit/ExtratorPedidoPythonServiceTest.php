<?php

namespace Tests\Unit;

use App\Services\ExtratorPedidoPythonService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtratorPedidoPythonServiceTest extends TestCase
{
    public function test_envia_pdf_no_campo_padrao_configurado(): void
    {
        config()->set('services.extrator_pedido.url', 'http://localhost:8010/extrair-pedido');
        config()->set('services.extrator_pedido.file_field', 'pdf');
        config()->set('services.extrator_pedido.retry_times', 0);

        Http::fake([
            'http://localhost:8010/extrair-pedido' => Http::response([
                'sucesso' => true,
                'dados' => ['pedido' => ['numero_pedido' => '123']],
            ], 200),
        ]);

        $arquivo = UploadedFile::fake()->createWithContent('pedido.pdf', '%PDF-1.4 teste');

        $dados = app(ExtratorPedidoPythonService::class)->processar($arquivo, 'PRODUTOS_PDF_SIERRA', 'req-test-1');

        $this->assertSame('123', data_get($dados, 'pedido.numero_pedido'));

        Http::assertSent(function (Request $request) {
            return str_contains((string) $request->body(), 'name="pdf"')
                && str_contains((string) $request->body(), 'name="tipo_importacao"');
        });
    }

    public function test_aplica_fallback_para_arquivo_quando_api_rejeita_campo_pdf(): void
    {
        config()->set('services.extrator_pedido.url', 'http://localhost:8010/extrair-pedido');
        config()->set('services.extrator_pedido.file_field', 'pdf');
        config()->set('services.extrator_pedido.retry_times', 0);

        Http::fake([
            'http://localhost:8010/extrair-pedido' => Http::sequence()
                ->push([
                    'detail' => [
                        ['type' => 'missing', 'loc' => ['body', 'arquivo'], 'msg' => 'Field required'],
                    ],
                ], 422)
                ->push([
                    'sucesso' => true,
                    'dados' => ['pedido' => ['numero_pedido' => '456']],
                ], 200),
        ]);

        $arquivo = UploadedFile::fake()->createWithContent('pedido.pdf', '%PDF-1.4 teste');

        $dados = app(ExtratorPedidoPythonService::class)->processar($arquivo, 'PRODUTOS_PDF_SIERRA', 'req-test-2');

        $this->assertSame('456', data_get($dados, 'pedido.numero_pedido'));
        Http::assertSentCount(2);

        $capturados = [];
        Http::assertSent(function (Request $request) use (&$capturados) {
            $capturados[] = (string) $request->body();
            return true;
        });

        $this->assertStringContainsString('name="pdf"', $capturados[0] ?? '');
        $this->assertStringContainsString('name="arquivo"', $capturados[1] ?? '');
    }

    public function test_forca_url_local_quando_configurada_url_remota_no_ambiente_local(): void
    {
        config()->set('app.env', 'local');
        config()->set('services.extrator_pedido.url', 'http://167.99.51.172:8010/extrair-pedido');
        config()->set('services.extrator_pedido.file_field', 'pdf');
        config()->set('services.extrator_pedido.force_local_url', true);
        config()->set('services.extrator_pedido.retry_times', 0);

        Http::fake([
            'http://127.0.0.1:8010/extrair-pedido' => Http::response([
                'sucesso' => true,
                'dados' => ['pedido' => ['numero_pedido' => '789']],
            ], 200),
        ]);

        $arquivo = UploadedFile::fake()->createWithContent('pedido.pdf', '%PDF-1.4 teste');
        $dados = app(ExtratorPedidoPythonService::class)->processar($arquivo, 'PRODUTOS_PDF_SIERRA', 'req-test-3');

        $this->assertSame('789', data_get($dados, 'pedido.numero_pedido'));
        Http::assertSent(fn(Request $request) => $request->url() === 'http://127.0.0.1:8010/extrair-pedido');
    }
}
