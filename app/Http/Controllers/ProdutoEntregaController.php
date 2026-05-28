<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoEntregaItemResource;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Services\EntregaProdutoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProdutoEntregaController extends Controller
{
    public function __construct(private readonly EntregaProdutoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProdutoEntregaItem::query()
            ->with([
                'pedido.cliente:id,nome',
                'pedidoItem.variacao.produto',
                'variacao.produto',
                'variacao.atributos',
                'depositoOrigem:id,nome',
                'depositoDestino:id,nome',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('pedido_id'), fn ($q) => $q->where('pedido_id', $request->integer('pedido_id')))
            ->when($request->filled('tipo_origem'), fn ($q) => $q->where('tipo_origem', $request->input('tipo_origem')))
            ->when($request->boolean('pendentes'), function ($q) {
                $q->whereIn('status', [
                    ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    ProdutoEntregaItem::STATUS_RESERVADO,
                    ProdutoEntregaItem::STATUS_PRONTO_EXPEDICAO,
                    ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO,
                ]);
            })
            ->orderByDesc('updated_at');

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        return ProdutoEntregaItemResource::collection($query->paginate($perPage))->response();
    }

    public function reservar(Request $request, ProdutoEntregaItem $item): ProdutoEntregaItemResource
    {
        $data = $this->validatePayload($request, true);

        $item = $this->service->reservarItem(
            $item,
            $data['deposito_id'] ?? null,
            $data['quantidade'] ?? null,
            auth()->id(),
            $data['observacao'] ?? null,
            $data['idempotency_key'] ?? null
        );

        return new ProdutoEntregaItemResource($item->load(['eventos', 'variacao.produto', 'depositoOrigem']));
    }

    public function receber(Request $request, ProdutoEntregaItem $item): ProdutoEntregaItemResource
    {
        $data = $this->validatePayload($request, true);

        $item = $this->service->receberItem(
            $item,
            $data['deposito_id'] ?? null,
            $data['quantidade'] ?? null,
            auth()->id(),
            $data['observacao'] ?? null,
            $data['idempotency_key'] ?? null
        );

        return new ProdutoEntregaItemResource($item->load(['eventos', 'variacao.produto', 'depositoDestino']));
    }

    public function expedir(Request $request, ProdutoEntregaItem $item): ProdutoEntregaItemResource
    {
        $data = $this->validatePayload($request, true);

        $item = $this->service->expedirItem(
            $item,
            $data['deposito_id'] ?? null,
            $data['quantidade'] ?? null,
            auth()->id(),
            $data['observacao'] ?? null,
            ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
            $data['idempotency_key'] ?? null
        );

        return new ProdutoEntregaItemResource($item->load(['eventos', 'variacao.produto', 'depositoOrigem']));
    }

    public function entregar(Request $request, ProdutoEntregaItem $item): ProdutoEntregaItemResource
    {
        $data = $this->validatePayload($request, false);

        $item = $this->service->entregarItem(
            $item,
            $data['quantidade'] ?? null,
            auth()->id(),
            $data['observacao'] ?? null,
            $data['idempotency_key'] ?? null
        );

        return new ProdutoEntregaItemResource($item->load(['eventos', 'variacao.produto']));
    }

    public function cancelar(Request $request, ProdutoEntregaItem $item): ProdutoEntregaItemResource
    {
        $data = $request->validate([
            'observacao' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $item = $this->service->cancelarItem(
            $item,
            auth()->id(),
            $data['observacao'] ?? null,
            $data['idempotency_key'] ?? null
        );

        return new ProdutoEntregaItemResource($item->load(['eventos', 'variacao.produto']));
    }

    public function estornar(Request $request, ProdutoEntregaEvento $evento): JsonResponse
    {
        $data = $request->validate([
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $estorno = $this->service->estornarEvento($evento, auth()->id(), $data['observacao'] ?? null);

        return response()->json([
            'message' => 'Evento estornado com sucesso.',
            'data' => $estorno->load('item'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request, bool $aceitaDeposito): array
    {
        return $request->validate([
            'quantidade' => ['nullable', 'integer', 'min:1'],
            'deposito_id' => [$aceitaDeposito ? 'nullable' : 'prohibited', 'integer', 'exists:depositos,id'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);
    }
}
