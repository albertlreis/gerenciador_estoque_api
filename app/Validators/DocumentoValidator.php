<?php

namespace App\Validators;

class DocumentoValidator
{
    public static function validarCPF(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) $d += $cpf[$c] * (($t + 1) - $c);
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) return false;
        }
        return true;
    }

    public static function validarCNPJ(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
        $peso1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $peso2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma1 = array_sum(array_map(fn($v, $p) => $v * $p, str_split(substr($cnpj, 0, 12)), $peso1));
        $dig1 = ($soma1 % 11) < 2 ? 0 : 11 - ($soma1 % 11);
        $soma2 = array_sum(array_map(fn($v, $p) => $v * $p, str_split(substr($cnpj, 0, 13)), $peso2));
        $dig2 = ($soma2 % 11) < 2 ? 0 : 11 - ($soma2 % 11);
        return $cnpj[12] == $dig1 && $cnpj[13] == $dig2;
    }
}
