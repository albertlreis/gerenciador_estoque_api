<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CommsProxyController
{
    private function comm(): PendingRequest
    {
        $base = rtrim((string) config('services.comms.base_url'), '/');

        // Padroniza nomes: api_key / api_secret
        $apiKey = (string) config('services.comms.api_key');
        $apiSecret = (string) config('services.comms.api_secret');

        if ($base === '' || $apiKey === '' || $apiSecret === '') {
            // Ajuda a detectar config faltando sem ficar “mudo”
            throw new RuntimeException('Config services.comms incompleta (base_url/api_key/api_secret).');
        }

        return Http::baseUrl($base)
            ->timeout(30)
            ->retry(2, 300, function ($exception) {
                // Retry só para falhas de conexão/timeouts (não para 401/422/etc)
                return $exception instanceof ConnectionException;
            })
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-API-KEY' => $apiKey,
                'X-API-SECRET' => $apiSecret,
            ]);
    }

    private function pass(HttpResponse $res): JsonResponse
    {
        // Se o provider retornar HTML/empty, evita json() null sem contexto
        $body = $res->json();
        if ($body === null) {
            $body = [
                'message' => 'Resposta inválida do serviço de comunicação.',
                'status' => $res->status(),
            ];
        }

        return response()->json($body, $res->status());
    }

    // Templates
    public function templatesIndex(Request $request): JsonResponse
    {
        return $this->pass($this->comm()->get('/templates', $request->query()));
    }

    public function templatesShow(string $id): JsonResponse
    {
        return $this->pass($this->comm()->get("/templates/$id"));
    }

    public function templatesStore(Request $request): JsonResponse
    {
        $this->authorizeManage();
        return $this->pass($this->comm()->post('/templates', $request->all()));
    }

    public function templatesUpdate(Request $request, string $id): JsonResponse
    {
        $this->authorizeManage();
        return $this->pass($this->comm()->put("/templates/$id", $request->all()));
    }

    public function templatesPreview(Request $request, string $id): JsonResponse
    {
        $this->authorizeManage();
        return $this->pass($this->comm()->post("/templates/$id/preview", $request->all()));
    }

    // Requests
    public function requestsIndex(Request $request): JsonResponse
    {
        return $this->pass($this->comm()->get('/requests', $request->query()));
    }

    public function requestsShow(string $id): JsonResponse
    {
        return $this->pass($this->comm()->get("/requests/$id"));
    }

    public function requestsCancel(string $id): JsonResponse
    {
        return $this->pass($this->comm()->post("/requests/$id/cancel"));
    }

    // Messages
    public function messagesIndex(Request $request): JsonResponse
    {
        return $this->pass($this->comm()->get('/messages', $request->query()));
    }

    public function messagesShow(string $id): JsonResponse
    {
        return $this->pass($this->comm()->get("/messages/$id"));
    }

    public function messagesRetry(string $id): JsonResponse
    {
        abort_unless(
            AuthHelper::hasPermissao('comunicacao.reprocessar') || AuthHelper::hasPermissao('comunicacao.messages.retry'),
            403,
            'Sem permissão para reprocessar comunicação.'
        );
        return $this->pass($this->comm()->post("/messages/$id/retry"));
    }

    public function historico(Request $request): JsonResponse
    {
        abort_unless(
            AuthHelper::hasPermissao('comunicacao.visualizar') || AuthHelper::hasPermissao('comunicacao.templates'),
            403,
            'Sem permissão para visualizar comunicação.'
        );
        $dados = $request->validate([
            'tipo' => ['required', 'in:pedido,conta_receber'],
            'id' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->pass($this->comm()->get('/messages', [
            'client_reference' => $dados['tipo'].':'.$dados['id'],
            'per_page' => $dados['per_page'] ?? 50,
        ]));
    }

    private function authorizeManage(): void
    {
        abort_unless(AuthHelper::hasPermissao('comunicacao.templates'), 403, 'Sem permissão para configurar comunicação.');
    }
}
