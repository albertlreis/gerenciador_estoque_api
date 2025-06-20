<?php

namespace App\Http\Resources;

use App\Models\Consignacao;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsignacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        $hoje = now();

        // Carrega todas as consignações do mesmo pedido
        $itens = Consignacao::where('pedido_id', $this->pedido_id)->get();

        $statusPedido = 'pendente';
        $temPendente = false;
        $temComprado = false;
        $temDevolvido = false;

        foreach ($itens as $item) {
            if ($item->status === 'pendente') {
                // Verifica se o prazo expirou
                if ($item->prazo_resposta && $item->prazo_resposta->lt($hoje)) {
                    $statusPedido = 'vencido';
                    break;
                }
                $temPendente = true;
            }
            if ($item->status === 'comprado') $temComprado = true;
            if ($item->status === 'devolvido') $temDevolvido = true;
        }

        if ($temPendente) {
            $statusPedido = 'pendente';
        } elseif ($temComprado && !$temDevolvido) {
            $statusPedido = 'comprado';
        } elseif ($temDevolvido && !$temComprado) {
            $statusPedido = 'devolvido';
        } elseif ($temComprado && $temDevolvido) {
            $statusPedido = 'parcial'; // Se quiser suportar status parcial
        }

        return [
            'id' => $this->id,
            'pedido_id' => $this->pedido_id,
            'numero_externo' => optional($this->pedido)->numero_externo,
            'cliente_nome' => optional($this->pedido->cliente)->nome,
            'vendedor_nome' => optional($this->pedido->usuario)->nome,
            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),
            'status' => $this->status,
            'status_calculado' => $statusPedido,
        ];
    }
}
