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
        $filtros = $request->validate([
            'status' => ['nullable', 'string'],
            'pedido_id' => ['nullable', 'integer'],
            'tipo_origem' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'deposito_id' => ['nullable', 'integer'],
            'bloqueados' => ['nullable', 'boolean'],
            'previsao_inicio' => ['nullable', 'date_format:Y-m-d'],
            'previsao_fim' => ['nullable', 'date_format:Y-m-d'],
            'pendentes' => ['nullable', 'boolean'],
            'entregaveis' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $busca = trim((string) ($filtros['q'] ?? ''));

        $query = ProdutoEntregaItem::query()
            ->with([
                'pedido.cliente:id,nome',
                'pedidoItem.variacao.produto',
                'variacao.produto',
                'variacao.atributos',
                'depositoOrigem:id,nome',
                'depositoDestino:id,nome',
            ])
            ->when(! empty($filtros['status']), fn ($q) => $q->where('status', $filtros['status']))
            ->when(! empty($filtros['pedido_id']), fn ($q) => $q->where('pedido_id', (int) $filtros['pedido_id']))
            ->when(! empty($filtros['tipo_origem']), fn ($q) => $q->where('tipo_origem', $filtros['tipo_origem']))
            ->when(! empty($filtros['deposito_id']), function ($q) use ($filtros) {
                $depositoId = (int) $filtros['deposito_id'];

                $q->where(function ($depositos) use ($depositoId) {
                    $depositos
                        ->where('id_deposito_origem', $depositoId)
                        ->orWhere('id_deposito_destino', $depositoId);
                });
            })
            ->when($request->boolean('bloqueados'), fn ($q) => $q->where('status', ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO))
            ->when(! empty($filtros['previsao_inicio']), fn ($q) => $q->whereDate('previsao_entrega', '>=', $filtros['previsao_inicio']))
            ->when(! empty($filtros['previsao_fim']), fn ($q) => $q->whereDate('previsao_entrega', '<=', $filtros['previsao_fim']))
            ->when($busca !== '', function ($q) use ($busca) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $busca) . '%';

                $q->where(function ($query) use ($busca, $like) {
                    if (ctype_digit($busca)) {
                        $query
                            ->orWhere('pedido_id', (int) $busca)
                            ->orWhere('origem_id', (int) $busca);
                    }

                    $query
                        ->orWhereHas('pedido', fn ($pedido) => $pedido->where('numero_externo', 'like', $like))
                        ->orWhereHas('pedido.cliente', fn ($cliente) => $cliente->where('nome', 'like', $like))
                        ->orWhereHas('variacao', function ($variacao) use ($like) {
                            $variacao
                                ->where('nome', 'like', $like)
                                ->orWhere('referencia', 'like', $like);
                        })
                        ->orWhereHas('variacao.produto', fn ($produto) => $produto->where('nome', 'like', $like))
                        ->orWhereHas('pedidoItem.variacao.produto', fn ($produto) => $produto->where('nome', 'like', $like));
                });
            })
            ->when($request->boolean('pendentes'), function ($q) {
                $q->whereIn('status', [
                    ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    ProdutoEntregaItem::STATUS_RESERVADO,
                    ProdutoEntregaItem::STATUS_PRONTO_EXPEDICAO,
                    ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO,
                ]);
            })
            ->when($request->boolean('entregaveis'), function ($q) {
                $q->whereIn('status', [
                    ProdutoEntregaItem::STATUS_EXPEDIDO,
                    ProdutoEntregaItem::STATUS_EXPEDIDO_PARCIAL,
                    ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL,
                ])->whereColumn('quantidade_expedida', '>', 'quantidade_entregue');
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
