<?php

namespace App\Domain\Importacao\DTO;

final class ProdutoImportadoDTO
{
    /** @param AtributoDTO[] $atributos */
    public function __construct(
        public string   $descricaoXml,
        public ?string  $referencia,       // cProd formatado
        public ?string  $unidade,
        public float    $quantidade,
        public float    $custoUnitXml,     // vUnCom (CUSTO)
        public float    $valorTotalXml,    // vProd
        public ?string  $observacao,
        public ?int     $idCategoria,      // informada no front se produto novo
        public ?int     $variacaoIdManual, // se operador selecionar manualmente
        public ?int     $variacaoIdEncontrada, // se já existir por referencia
        public ?float   $precoCadastrado,  // do BD, se existir
        public ?float   $custoCadastrado,  // do BD, se existir
        public ?string  $descricaoFinal,   // exibida e editável no front
        public array    $atributos = [],
        public ?int     $pedidoId = null   // vinculação opcional
    ) {}
}
