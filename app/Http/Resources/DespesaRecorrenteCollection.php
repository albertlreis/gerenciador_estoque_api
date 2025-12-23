<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class DespesaRecorrenteCollection extends ResourceCollection
{
    public function toArray($request): array
    {
        return [
            'data' => DespesaRecorrenteResource::collection($this->collection),
        ];
    }

    public function with($request): array
    {
        $p = $this->resource; // paginator

        return [
            'meta' => [
                'current_page' => method_exists($p, 'currentPage') ? $p->currentPage() : null,
                'per_page' => method_exists($p, 'perPage') ? $p->perPage() : null,
                'total' => method_exists($p, 'total') ? $p->total() : null,
                'last_page' => method_exists($p, 'lastPage') ? $p->lastPage() : null,
            ],
        ];
    }
}
