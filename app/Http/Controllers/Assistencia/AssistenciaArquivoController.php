<?php

namespace App\Http\Controllers\Assistencia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistencia\UploadAssistenciaArquivoRequest;
use App\Http\Resources\AssistenciaArquivoResource;
use App\Models\AssistenciaArquivo;
use App\Models\AssistenciaChamado;
use App\Models\AssistenciaChamadoItem;
use App\Services\Assistencia\AssistenciaArquivoService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlador para gerenciar arquivos/fotos de chamados e itens.
 */
class AssistenciaArquivoController extends Controller
{
    public function __construct(
        protected AssistenciaArquivoService $service
    ) {}

    /**
     * Lista arquivos vinculados a um chamado.
     *
     * @param int $id ID do chamado
     * @return JsonResponse
     */
    public function listByChamado(int $id): JsonResponse
    {
        $chamado = AssistenciaChamado::query()->findOrFail($id);
        $arquivos = $chamado->arquivos()
            ->whereNull('item_id')
            ->latest('id')
            ->get();

        return response()->json(AssistenciaArquivoResource::collection($arquivos));
    }

    /**
     * Envia (faz upload) de uma ou mais fotos para um chamado.
     *
     * @param UploadAssistenciaArquivoRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function uploadToChamado(UploadAssistenciaArquivoRequest $request, int $id): JsonResponse
    {
        $chamado = AssistenciaChamado::query()->findOrFail($id);

        $saved = $this->service->storeForChamado(
            chamado: $chamado,
            files: $request->file('arquivos', []),
            tipo: $request->string('tipo')->toString()
        );

        return response()->json(AssistenciaArquivoResource::collection($saved), 201);
    }

    /**
     * Lista arquivos vinculados a um item do chamado.
     *
     * @param int $itemId ID do item
     * @return JsonResponse
     */
    public function listByItem(int $itemId): JsonResponse
    {
        $item = AssistenciaChamadoItem::query()->findOrFail($itemId);
        $arquivos = $item->arquivos()->latest('id')->get();

        return response()->json(AssistenciaArquivoResource::collection($arquivos));
    }

    /**
     * Envia (faz upload) de uma ou mais fotos para um item do chamado.
     *
     * @param UploadAssistenciaArquivoRequest $request
     * @param int $itemId
     * @return JsonResponse
     */
    public function uploadToItem(UploadAssistenciaArquivoRequest $request, int $itemId): JsonResponse
    {
        $item = AssistenciaChamadoItem::query()->findOrFail($itemId);

        $saved = $this->service->storeForItem(
            item: $item,
            files: $request->file('arquivos', []),
            tipo: $request->string('tipo')->toString()
        );

        return response()->json(AssistenciaArquivoResource::collection($saved), 201);
    }

    /**
     * Retorna metadados do arquivo e opcionalmente faz stream do conteúdo.
     * Use `?download=1` para forçar download.
     *
     * @param int $arquivo
     * @return JsonResponse|StreamedResponse
     */
    public function show(int $arquivo)
    {
        $model = AssistenciaArquivo::query()->findOrFail($arquivo);

        if (request()->boolean('download')) {
            return $this->service->stream($model, /* asDownload */ true);
        }

        // Metadados + URL pública (Storage::url)
        return response()->json(new AssistenciaArquivoResource($model));
    }

    /**
     * Exclui um arquivo (remove do Storage e do banco).
     *
     * @param int $arquivo
     * @return JsonResponse
     */
    public function destroy(int $arquivo): JsonResponse
    {
        $model = AssistenciaArquivo::query()->findOrFail($arquivo);
        $this->service->delete($model);

        return response()->json(['message' => 'Arquivo removido com sucesso.']);
    }
}
