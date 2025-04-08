<?php

namespace App\Http\Controllers;

use App\Models\Parceiro;
use Illuminate\Http\Request;

class ParceiroController extends Controller
{
    public function index()
    {
        return response()->json(Parceiro::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'tipo'      => 'required|string|max:50',
            'documento' => 'required|string|max:50',
            'email'     => 'required|email|max:100',
            'telefone'  => 'nullable|string|max:50',
            'endereco'  => 'nullable|string',
        ]);

        $parceiro = Parceiro::create($validated);
        return response()->json($parceiro, 201);
    }

    public function show(Parceiro $parceiro)
    {
        return response()->json($parceiro);
    }

    public function update(Request $request, Parceiro $parceiro)
    {
        $validated = $request->validate([
            'nome'      => 'sometimes|required|string|max:255',
            'tipo'      => 'sometimes|required|string|max:50',
            'documento' => 'sometimes|required|string|max:50',
            'email'     => 'sometimes|required|email|max:100',
            'telefone'  => 'nullable|string|max:50',
            'endereco'  => 'nullable|string',
        ]);

        $parceiro->update($validated);
        return response()->json($parceiro);
    }

    public function destroy(Parceiro $parceiro)
    {
        $parceiro->delete();
        return response()->json(null, 204);
    }
}
