<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EventoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'tipo' => (string) $this->tipo,
            'titulo' => (string) $this->titulo,
            'descricao' => $this->descricao,
            'local' => $this->local,
            'inicio_em' => optional($this->inicio_em)?->toIso8601String(),
            'fim_em' => optional($this->fim_em)?->toIso8601String(),
            'criado_por' => $this->criado_por ? (int) $this->criado_por : null,
            'criador' => $this->whenLoaded('criador', fn () => [
                'id' => (int) $this->criador->id,
                'nome' => (string) $this->criador->nome,
                'email' => (string) $this->criador->email,
            ]),
            'participantes' => $this->whenLoaded('participantes', fn () => $this->participantes->map(fn ($participante) => [
                'id' => (int) $participante->id,
                'user_id' => (int) $participante->user_id,
                'obrigatorio' => (bool) $participante->obrigatorio,
                'status_confirmacao' => $participante->status_confirmacao,
                'usuario' => $participante->relationLoaded('usuario') && $participante->usuario ? [
                    'id' => (int) $participante->usuario->id,
                    'nome' => (string) $participante->usuario->nome,
                    'email' => (string) $participante->usuario->email,
                ] : null,
            ])->values()->all()),
            'google_sync' => [
                'event_id' => $this->google_event_id,
                'calendar_id' => $this->google_calendar_id,
                'status' => $this->google_sync_status,
                'last_synced_at' => optional($this->google_last_synced_at)?->toIso8601String(),
            ],
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
