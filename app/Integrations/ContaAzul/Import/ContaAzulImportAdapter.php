<?php

namespace App\Integrations\ContaAzul\Import;

interface ContaAzulImportAdapter
{
    public function tipoEntidade(): string;

    public function stagingTable(): string;

    /**
     * @param  array<string, mixed>  $config
     * @return array{method:string, path:string, query:array<string, mixed>, body:array<string, mixed>}
     */
    public function buildRequest(array $config, int $pagina, int $pageSize): array;
}
