<?php

namespace App\Http\Controllers;

use App\Models\Deposito;
use Illuminate\Http\Request;

class DepositoController extends Controller
{
    public function index()
    {
        return response()->json(Deposito::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'     => 'required|string|max:255',
            'endereco' => 'nullable|string',
        ]);

        $deposito = Deposito::create($validated);
        return response()->json($deposito, 201);
    }

    public function show(Deposito $deposito)
    {
        return response()->json($deposito);
    }

    public function update(Request $request, Deposito $deposito)
    {
        $validated = $request->validate([
            'nome'     => 'sometimes|required|string|max:255',
            'endereco' => 'nullable|string',
        ]);

        $deposito->update($validated);
        return response()->json($deposito);
    }

    public function destroy(Deposito $deposito)
    {
        $deposito->delete();
        return response()->json(null, 204);
    }
}
