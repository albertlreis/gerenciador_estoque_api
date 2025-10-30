<?php

namespace App\Policies;

use App\Models\ContaPagar;
use App\Models\AcessoUsuario;

class ContaPagarPolicy
{
    public function viewAny(AcessoUsuario $user): bool { return true; }
    public function view(AcessoUsuario $user, ContaPagar $conta): bool { return true; }
    public function create(AcessoUsuario $user): bool { return true; }
    public function update(AcessoUsuario $user, ContaPagar $conta): bool { return true; }
    public function delete(AcessoUsuario $user, ContaPagar $conta): bool { return true; }
    public function pagar(AcessoUsuario $user, ContaPagar $conta): bool { return true; }
    public function estornar(AcessoUsuario $user, ContaPagar $conta): bool { return true; }
}
