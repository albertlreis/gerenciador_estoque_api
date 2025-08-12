<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collection para retornar uma lista paginada de pedidos com metadados.
 *
 * @property \Illuminate\Pagination\LengthAwarePaginator $collection
 */
class PedidoListCollection extends ResourceCollection
{
    /**
     * Transforma a coleção de recursos em um array estruturado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $paginator = $this->resource;

        return [
            'data' => PedidoListResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ],
        ];
    }
}
