<?php

namespace App\Services;

use App\Jobs\GenerateDocumentExport;
use App\Models\DocumentExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentExportService
{
    public const SYNC_ITEM_LIMIT = 10;

    public function enqueueWhenLarge(
        string $type,
        int $subjectId,
        Request $request,
        int $itemCount,
        array $payload
    ): ?JsonResponse {
        if ($itemCount <= self::SYNC_ITEM_LIMIT || $request->headers->get('X-Document-Worker') === '1') {
            return null;
        }

        $export = DocumentExport::create([
            'id' => (string) Str::uuid(),
            'user_id' => (int) $request->user()->id,
            'type' => $type,
            'subject_id' => $subjectId,
            'status' => 'pending',
            'request_payload' => $payload,
            'request_method' => $request->method(),
            'expires_at' => now()->addDay(),
        ]);

        GenerateDocumentExport::dispatch($export->id)
            ->onConnection('database')
            ->onQueue('documents');

        return response()->json($this->responsePayload($export), 202);
    }

    public function responsePayload(DocumentExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'status_url' => url("/api/v1/document-exports/{$export->id}"),
            'download_url' => url("/api/v1/document-exports/{$export->id}/download"),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
