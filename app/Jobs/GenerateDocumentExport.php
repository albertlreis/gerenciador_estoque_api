<?php

namespace App\Jobs;

use App\Http\Controllers\ConsignacaoController;
use App\Http\Controllers\PedidoController;
use App\Models\DocumentExport;
use App\Support\Logging\SierraLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateDocumentExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $exportId)
    {
        $this->onConnection('database');
        $this->onQueue('documents');
    }

    public function handle(): void
    {
        $export = DocumentExport::findOrFail($this->exportId);
        if ($export->expires_at->isPast()) {
            $export->update(['status' => 'expired', 'error_code' => 'EXPORT_EXPIRED']);
            return;
        }

        $export->update(['status' => 'processing', 'started_at' => now(), 'error_code' => null, 'error_message' => null]);
        Auth::onceUsingId($export->user_id);

        $request = Request::create('/internal/document-export', $export->request_method, $export->request_payload ?? []);
        $request->headers->set('X-Document-Worker', '1');
        $request->setUserResolver(fn () => Auth::user());

        $startedAt = microtime(true);
        $response = match ($export->type) {
            'consignacao_roteiro' => app(ConsignacaoController::class)->gerarPdf($export->subject_id, $request),
            'pedido_roteiro' => app(PedidoController::class)->roteiroPdf($export->subject_id, $request),
            'pedido_nota_entrega' => app()->call(
                [app(PedidoController::class), 'notaEntregaPdf'],
                ['pedidoId' => $export->subject_id, 'request' => $request]
            ),
            default => throw new RuntimeException('Unsupported document export type.'),
        };

        if ($response->getStatusCode() >= 400 || !method_exists($response, 'getContent')) {
            throw new RuntimeException('Document renderer returned an invalid response.');
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Document renderer returned an empty file.');
        }

        $path = "document-exports/{$export->id}.pdf";
        Storage::disk('local')->put($path, $content);
        $disposition = (string) $response->headers->get('Content-Disposition');
        preg_match('/filename="?([^";]+)"?/i', $disposition, $matches);

        $export->update([
            'status' => 'completed',
            'path' => $path,
            'filename' => $matches[1] ?? "documento-{$export->subject_id}.pdf",
            'mime_type' => $response->headers->get('Content-Type', 'application/pdf'),
            'completed_at' => now(),
        ]);

        SierraLog::job('job.document_export.completed', [
            'entity_type' => 'document_export',
            'entity_id' => $export->id,
            'document_type' => $export->type,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'response_bytes' => strlen($content),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        DocumentExport::whereKey($this->exportId)->update([
            'status' => 'failed',
            'error_code' => 'DOCUMENT_GENERATION_FAILED',
            'error_message' => 'Nao foi possivel gerar o documento. Tente novamente.',
            'completed_at' => now(),
        ]);

        SierraLog::job('job.document_export.failed', [
            'entity_type' => 'document_export',
            'entity_id' => $this->exportId,
            'exception_class' => get_class($exception),
        ], 'error');
    }
}
