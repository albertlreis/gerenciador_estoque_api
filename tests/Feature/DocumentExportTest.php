<?php

namespace Tests\Feature;

use App\Http\Controllers\PedidoController;
use App\Jobs\GenerateDocumentExport;
use App\Models\DocumentExport;
use App\Models\Usuario;
use App\Services\DocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DocumentExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_documento_pequeno_permanece_sincrono_e_grande_entra_na_fila_documents(): void
    {
        Bus::fake();
        $user = $this->usuario('exportador@example.test');
        $request = Request::create('/api/v1/pedidos/10/pdf/roteiro', 'GET', ['item_ids' => range(1, 11)]);
        $request->setUserResolver(fn () => $user);

        $service = app(DocumentExportService::class);
        $this->assertNull($service->enqueueWhenLarge('pedido_roteiro', 10, $request, 10, []));

        $response = $service->enqueueWhenLarge(
            'pedido_roteiro',
            10,
            $request,
            11,
            ['item_ids' => range(1, 11)]
        );

        $this->assertSame(202, $response?->getStatusCode());
        $export = DocumentExport::firstOrFail();
        $this->assertSame('pending', $export->status);
        $this->assertSame($user->id, $export->user_id);
        Bus::assertDispatched(GenerateDocumentExport::class, function (GenerateDocumentExport $job) use ($export) {
            return $job->exportId === $export->id
                && $job->connection === 'database'
                && $job->queue === 'documents';
        });
    }

    public function test_export_so_pode_ser_consultado_e_baixado_pelo_solicitante_e_expira(): void
    {
        Storage::fake('local');
        $owner = $this->usuario('owner.export@example.test');
        $other = $this->usuario('other.export@example.test');
        Storage::disk('local')->put('document-exports/ready.pdf', '%PDF-test');
        $export = DocumentExport::create([
            'id' => '39ffca87-b432-4cde-9060-24bcae6cd673',
            'user_id' => $owner->id,
            'type' => 'pedido_roteiro',
            'subject_id' => 10,
            'status' => 'completed',
            'request_payload' => [],
            'request_method' => 'GET',
            'path' => 'document-exports/ready.pdf',
            'filename' => 'roteiro.pdf',
            'mime_type' => 'application/pdf',
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/document-exports/{$export->id}")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/document-exports/{$export->id}")
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->get("/api/v1/document-exports/{$export->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $export->update(['expires_at' => now()->subMinute()]);
        $this->get("/api/v1/document-exports/{$export->id}/download")->assertStatus(410);
    }

    public function test_worker_restaura_usuario_no_request_guard_e_limpa_contexto_ao_final(): void
    {
        Storage::fake('local');
        Auth::forgetUser();
        $user = $this->usuario('worker.export@example.test');
        $export = $this->export($user, 'c7683a69-bdaa-4998-9323-5e7da4e9d99f');

        $controller = Mockery::mock(PedidoController::class);
        $controller->shouldReceive('notaEntregaPdf')
            ->once()
            ->withArgs(function (int $pedidoId, Request $request) use ($user): bool {
                $this->assertSame(91, $pedidoId);
                $this->assertTrue(Auth::check());
                $this->assertSame($user->id, Auth::id());
                $this->assertSame($user->id, $request->user()?->id);
                $this->assertSame('1', $request->headers->get('X-Document-Worker'));

                return true;
            })
            ->andReturn(response('%PDF-worker-test', 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="nota-entrega.pdf"',
            ]));
        $this->app->instance(PedidoController::class, $controller);

        (new GenerateDocumentExport($export->id))->handle();

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertSame('nota-entrega.pdf', $export->filename);
        Storage::disk('local')->assertExists("document-exports/{$export->id}.pdf");
        $this->assertFalse(Auth::check());
    }

    public function test_worker_falha_sem_retry_quando_usuario_esta_inativo_ou_foi_removido(): void
    {
        Auth::forgetUser();
        $inactive = $this->usuario('inactive.export@example.test');
        $inactive->update(['ativo' => false]);
        $inactiveExport = $this->export($inactive, '39f50ac8-b5ee-470a-b9ee-471384f231aa');

        $removed = $this->usuario('removed.export@example.test');
        $removedExport = $this->export($removed, 'dfe64324-78f1-43ad-b123-8e5f031f53fd');
        $removed->delete();

        $controller = Mockery::mock(PedidoController::class);
        $controller->shouldNotReceive('notaEntregaPdf');
        $this->app->instance(PedidoController::class, $controller);

        (new GenerateDocumentExport($inactiveExport->id))->handle();
        (new GenerateDocumentExport($removedExport->id))->handle();

        foreach ([$inactiveExport, $removedExport] as $export) {
            $export->refresh();
            $this->assertSame('failed', $export->status);
            $this->assertSame('EXPORT_USER_UNAVAILABLE', $export->error_code);
            $this->assertNotNull($export->completed_at);
        }
        $this->assertFalse(Auth::check());
    }

    public function test_worker_limpa_contexto_e_preserva_retries_para_falha_transitoria(): void
    {
        Auth::forgetUser();
        $user = $this->usuario('retry.export@example.test');
        $export = $this->export($user, '4087224a-2914-4623-80a3-2374bfb438ec');
        $failure = new RuntimeException('renderer temporarily unavailable');

        $controller = Mockery::mock(PedidoController::class);
        $controller->shouldReceive('notaEntregaPdf')->once()->andThrow($failure);
        $this->app->instance(PedidoController::class, $controller);

        $job = new GenerateDocumentExport($export->id);
        $this->assertSame(3, $job->tries);

        try {
            $job->handle();
            $this->fail('A falha transitoria deveria ser propagada para a fila tentar novamente.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertFalse(Auth::check());
        $this->assertSame('processing', $export->fresh()->status);

        $job->failed($failure);
        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame('DOCUMENT_GENERATION_FAILED', $export->error_code);
        $this->assertSame('Nao foi possivel gerar o documento. Tente novamente.', $export->error_message);
    }

    private function export(Usuario $user, string $id): DocumentExport
    {
        return DocumentExport::create([
            'id' => $id,
            'user_id' => $user->id,
            'type' => 'pedido_nota_entrega',
            'subject_id' => 91,
            'status' => 'pending',
            'request_payload' => ['acao' => 'somente_pdf', 'itens' => [['produto_entrega_item_id' => 1]]],
            'request_method' => 'POST',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function usuario(string $email): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Export',
            'email' => $email,
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
    }
}
