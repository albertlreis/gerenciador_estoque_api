<?php

namespace App\Policies;

use App\Models\ContaPagar;
use App\Models\Usuario;

class ContaPagarPolicy
{
    public function viewAny(Usuario $user): bool { return $user->can('contas.pagar.view'); }
    public function view(Usuario $user, ContaPagar $conta): bool { return $user->can('contas.pagar.view'); }
    public function create(Usuario $user): bool { return $user->can('contas.pagar.create'); }
    public function update(Usuario $user, ContaPagar $conta): bool { return $user->can('contas.pagar.update'); }
    public function delete(Usuario $user, ContaPagar $conta): bool { return $user->can('contas.pagar.delete'); }

    public function pagar(Usuario $user, ContaPagar $conta): bool { return $user->can('contas.pagar.pagar'); }
    public function estornar(Usuario $user, ContaPagar $conta): bool { return $user->can('contas.pagar.estornar'); }
}
