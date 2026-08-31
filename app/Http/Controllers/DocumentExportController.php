<?php

namespace App\Http\Controllers;

use App\Models\DocumentExport;
use App\Services\DocumentExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentExportController extends Controller
{
    public function show(Request $request, string $id, DocumentExportService $service): JsonResponse
    {
        $export = $this->ownedExport($request, $id);
        if ($export->expires_at->isPast() && $export->status !== 'expired') {
            Storage::disk('local')->delete((string) $export->path);
            $export->update(['status' => 'expired', 'path' => null]);
        }

        return response()->json($service->responsePayload($export) + [
            'error' => $export->status === 'failed' ? [
                'code' => $export->error_code,
                'message' => $export->error_message,
            ] : null,
        ]);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $export = $this->ownedExport($request, $id);
        if ($export->expires_at->isPast()) {
            return response()->json(['message' => 'Este documento expirou.'], 410);
        }
        if ($export->status !== 'completed' || !$export->path || !Storage::disk('local')->exists($export->path)) {
            return response()->json(['message' => 'O documento ainda nao esta disponivel.'], 409);
        }

        return Storage::disk('local')->download($export->path, $export->filename, [
            'Content-Type' => $export->mime_type ?: 'application/pdf',
        ]);
    }

    private function ownedExport(Request $request, string $id): DocumentExport
    {
        return DocumentExport::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
