<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProdutoOutletController extends Controller
{
    public function index(): Collection
    {
        return DB::table('produtos')->where('is_outlet', true)->get();
    }

    public function removerOutlet($id): JsonResponse
    {
        DB::table('produtos')->where('id', $id)->update(['is_outlet' => false]);
        return response()->json(['success' => true]);
    }

}
