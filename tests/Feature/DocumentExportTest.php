<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentExport;
use App\Models\DocumentExport;
use App\Models\Usuario;
use App\Services\DocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
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
