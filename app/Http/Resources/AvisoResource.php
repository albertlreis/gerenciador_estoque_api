<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;

class AvisoResource extends JsonResource
{
    public function toArray($request): array
    {
        $agora = CarbonImmutable::now(config('app.timezone', 'America/Sao_Paulo'));
        $inicio = $this->data_inicio ? CarbonImmutable::instance($this->data_inicio) : null;
        $fim = $this->data_fim ? CarbonImmutable::instance($this->data_fim) : null;

        $vigente = (bool) $this->ativo
            && ($inicio === null || $inicio->lessThanOrEqualTo($agora))
            && ($fim === null || $fim->greaterThanOrEqualTo($agora));

        return [
            'id' => (int) $this->id,
            'titulo' => (string) $this->titulo,
            'conteudo' => (string) $this->conteudo,
            'ativo' => (bool) $this->ativo,
            'esta_vigente' => $vigente,
            'data_inicio' => optional($this->data_inicio)?->toIso8601String(),
            'data_fim' => optional($this->data_fim)?->toIso8601String(),
            'criado_por' => $this->criado_por ? (int) $this->criado_por : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
