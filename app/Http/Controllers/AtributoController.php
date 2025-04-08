<?php

namespace App\Http\Controllers;

use App\Models\Atributo;
use Illuminate\Http\Request;

class AtributoController extends Controller
{
    public function index()
    {
        return response()->json(Atributo::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
        ]);

        $atributo = Atributo::create($validated);
        return response()->json($atributo, 201);
    }

    public function show(Atributo $atributo)
    {
        return response()->json($atributo);
    }

    public function update(Request $request, Atributo $atributo)
    {
        $validated = $request->validate([
            'nome' => 'sometimes|required|string|max:255',
        ]);

        $atributo->update($validated);
        return response()->json($atributo);
    }

    public function destroy(Atributo $atributo)
    {
        $atributo->delete();
        return response()->json(null, 204);
    }
}
