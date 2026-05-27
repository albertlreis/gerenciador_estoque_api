<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaChamadoLogResource extends JsonResource
{
    public function toArray($request): array
    {
        $context = is_array($this->context_json ?? null) ? $this->context_json : [];
        $meta = $context['meta'] ?? null;

        return [
            'id' => $this->id,
            'status_de' => $context['status_de'] ?? null,
            'status_para' => $context['status_para'] ?? $this->status,
            'mensagem' => $this->message,
            'meta' => $meta,
            'usuario_id' => $this->actor_id,
            'created_at' => optional($this->occurred_at ?? $this->created_at)->toDateTimeString(),
        ];
    }
}
