<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\JsonResponse;

class FornecedorController extends Controller
{
    /**
     * Lista todos os fornecedores.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $fornecedores = Fornecedor::select('id', 'nome')->orderBy('nome')->get();
        return response()->json($fornecedores);
    }
}
