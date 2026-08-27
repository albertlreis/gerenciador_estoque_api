<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Http\Resources\ProdutoEntregaItemResource;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Services\EntregaProdutoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoRecebimentoController extends Controller
{
    public function __construct(private readonly EntregaProdutoService $entregas) {}

    public function store(Request $request, Pedido $pedido): JsonResponse
    {
        if (! AuthHelper::hasPermissao('estoque.movimentar')) {
            return response()->json([
                'message' => 'Sem permissao para registrar recebimento no estoque.',
            ], 403);
        }

        $data = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_entrega_item_id' => ['required', 'integer', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
            'itens.*.id_deposito_destino' => ['required', 'integer', 'exists:depositos,id'],
            'itens.*.ocorrido_em' => ['required', 'date'],
            'itens.*.observacao' => ['nullable', 'string', 'max:1000'],
            'itens.*.idempotency_key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:-]+$/', 'distinct'],
            'aplicar_status_ao_concluir' => ['sometimes', 'boolean'],
        ]);

        $ids = collect($data['itens'])->pluck('produto_entrega_item_id')->map(fn ($id) => (int) $id);
        $itensPedido = ProdutoEntregaItem::query()
            ->where('pedido_id', $pedido->id)
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($itensPedido->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages([
                'itens' => ['Todos os itens devem pertencer ao fluxo principal deste pedido.'],
            ]);
        }

        $processados = DB::transaction(function () use ($data, $itensPedido) {
            $resultado = collect();

            foreach ($data['itens'] as $indice => $entrada) {
                /** @var ProdutoEntregaItem $item */
                $item = $itensPedido->get((int) $entrada['produto_entrega_item_id']);
                $quantidade = (int) $entrada['quantidade'];
                $idempotencyKey = trim((string) ($entrada['idempotency_key'] ?? ''));
                $depositoDestinoId = (int) $entrada['id_deposito_destino'];
                $ocorridoEm = Carbon::parse($entrada['ocorrido_em'], config('app.timezone'));

                $eventoComMesmaChave = ProdutoEntregaEvento::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                $eventoExistente = $eventoComMesmaChave
                    && (int) $eventoComMesmaChave->produto_entrega_item_id === (int) $item->id
                    && $eventoComMesmaChave->tipo_evento === ProdutoEntregaEvento::RECEBIDO_ESTOQUE
                        ? $eventoComMesmaChave
                        : null;

                if ($eventoComMesmaChave && ! $eventoExistente) {
                    throw ValidationException::withMessages([
                        "itens.{$indice}.idempotency_key" => ['Chave de idempotencia ja utilizada em outra operacao.'],
                    ]);
                }

                if ($eventoExistente) {
                    $mesmoPayload = (int) $eventoExistente->quantidade === $quantidade
                        && (int) $eventoExistente->id_deposito_destino === $depositoDestinoId
                        && $eventoExistente->ocorrido_em !== null
                        && $eventoExistente->ocorrido_em->getTimestamp() === $ocorridoEm->getTimestamp();

                    if (! $mesmoPayload) {
                        throw ValidationException::withMessages([
                            "itens.{$indice}.idempotency_key" => [
                                'Chave de idempotencia ja utilizada com quantidade, deposito ou data diferentes.',
                            ],
                        ]);
                    }

                    $resultado->push($item->fresh(['eventos']));

                    continue;
                }

                $pendente = max(0, (int) $item->quantidade_total - (int) $item->quantidade_recebida);

                if ($item->status === ProdutoEntregaItem::STATUS_CANCELADO) {
                    throw ValidationException::withMessages([
                        "itens.{$indice}.produto_entrega_item_id" => ['Item cancelado nao pode ser recebido.'],
                    ]);
                }

                if ($quantidade > $pendente) {
                    throw ValidationException::withMessages([
                        "itens.{$indice}.quantidade" => ["Quantidade excede o pendente de recebimento ({$pendente})."],
                    ]);
                }

                $resultado->push($this->entregas->receberItem(
                    $item,
                    $depositoDestinoId,
                    $quantidade,
                    auth()->id(),
                    $entrada['observacao'] ?? 'Recebimento da fabrica registrado pelo pedido.',
                    $idempotencyKey,
                    ocorridoEm: $ocorridoEm,
                    rejeitarExcesso: true
                ));
            }

            return $resultado;
        });

        $resumo = $this->entregas->resumoPedido($pedido->fresh('entregaItens'));
        $concluido = (int) $resumo['quantidade_total'] > 0
            && (int) $resumo['quantidade_recebida'] >= (int) $resumo['quantidade_total'];
        $statusAplicado = false;

        if ($concluido && ($data['aplicar_status_ao_concluir'] ?? true)) {
            $statusAplicado = $pedido->historicoStatus()
                ->where('status', PedidoStatus::ENTREGA_ESTOQUE->value)
                ->exists();
            $statusAtual = (string) ($pedido->statusAtual()->first()?->getRawOriginal('status') ?? '');
            $clienteJaAtendido = in_array($statusAtual, [
                PedidoStatus::ENVIO_CLIENTE->value,
                PedidoStatus::ENTREGA_CLIENTE->value,
                PedidoStatus::FINALIZADO->value,
            ], true);

            if (! $statusAplicado && ! $clienteJaAtendido) {
                $pedido->historicoStatus()->create([
                    'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
                    'data_status' => now(),
                    'usuario_id' => auth()->id(),
                    'observacoes' => 'Recebimento integral da fabrica registrado pelo fluxo operacional.',
                ]);
                $statusAplicado = true;
            }
        }

        return response()->json([
            'message' => $concluido
                ? 'Recebimento concluido com sucesso.'
                : 'Recebimento parcial registrado com sucesso.',
            'status_aplicado' => $statusAplicado,
            'status_envio' => $this->entregas->statusOperacionalPedido($pedido->fresh('entregaItens')),
            'itens' => ProdutoEntregaItemResource::collection($processados)->resolve($request),
        ]);
    }
}
