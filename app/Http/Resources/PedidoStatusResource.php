<?php

namespace App\Http\Resources;

use App\Services\PedidoStatusFluxoService;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoStatusResource extends JsonResource
{
    public function toArray($request): array
    {
        $status = (string) $this->getRawOriginal('status');
        $meta = app(PedidoStatusFluxoService::class)->statusMeta($status);

        return [
            'status' => $status,
            'label' => $status ? $meta['label'] : '—',
            'icone' => $meta['icone'],
            'cor' => $meta['cor'],
            'severidade' => $meta['severidade'],
            'data_status' => $this->data_status,
            'observacoes' => $this->observacoes,
            'usuario' => $this->usuario->nome ?? '—',
        ];
    }
}
