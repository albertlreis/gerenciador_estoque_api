<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DespesaRecorrente */
class DespesaRecorrenteShowResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'despesa' => new DespesaRecorrenteResource($this->resource),

            'execucoes' => $this->whenLoaded('execucoes', function () {
                return DespesaRecorrenteExecucaoResource::collection($this->execucoes);
            }),
        ];
    }
}
