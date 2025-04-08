<?php

namespace App\Http\Controllers;

use App\Models\Atributo;
use App\Models\AtributoValor;
use Illuminate\Http\Request;

class AtributoValorController extends Controller
{
    public function index(Atributo $atributo)
    {
        return response()->json($atributo->valores);
    }

    public function store(Request $request, Atributo $atributo)
    {
        $validated = $request->validate([
            'valor' => 'required|string|max:255',
        ]);

        $validated['id_atributo'] = $atributo->id;
        $valor = AtributoValor::create($validated);
        return response()->json($valor, 201);
    }

    public function show(Atributo $atributo, AtributoValor $valor)
    {
        if ($valor->id_atributo !== $atributo->id) {
            return response()->json(['error' => 'Valor não pertence a este atributo'], 404);
        }
        return response()->json($valor);
    }

    public function update(Request $request, Atributo $atributo, AtributoValor $valor)
    {
        if ($valor->id_atributo !== $atributo->id) {
            return response()->json(['error' => 'Valor não pertence a este atributo'], 404);
        }

        $validated = $request->validate([
            'valor' => 'sometimes|required|string|max:255',
        ]);

        $valor->update($validated);
        return response()->json($valor);
    }

    public function destroy(Atributo $atributo, AtributoValor $valor)
    {
        if ($valor->id_atributo !== $atributo->id) {
            return response()->json(['error' => 'Valor não pertence a este atributo'], 404);
        }

        $valor->delete();
        return response()->json(null, 204);
    }
}
