<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use App\Services\ClienteService;
use App\Http\Requests\StoreClienteRequest;
use Illuminate\Validation\ValidationException;

class ClienteController extends Controller
{
    protected ClienteService $service;

    public function __construct(ClienteService $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        return response()->json(Cliente::all());
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreClienteRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($this->service->documentoDuplicado($data['documento'])) {
            throw ValidationException::withMessages(['documento' => 'Documento já cadastrado.']);
        }

        if (!$this->service->validarDocumento($data['documento'], $data['tipo'])) {
            throw ValidationException::withMessages(['documento' => 'Documento inválido.']);
        }

        $data = $this->service->preencherEnderecoViaCep($data);

        $cliente = Cliente::create($data);
        return response()->json($cliente, 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(StoreClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['documento']) && $data['documento'] !== $cliente->documento) {
            if ($this->service->documentoDuplicado($data['documento'], $cliente->id)) {
                throw ValidationException::withMessages(['documento' => 'Documento já cadastrado.']);
            }
            $tipo = $data['tipo'] ?? $cliente->tipo;
            if (!$this->service->validarDocumento($data['documento'], $tipo)) {
                throw ValidationException::withMessages(['documento' => 'Documento inválido.']);
            }
        }

        $data = $this->service->preencherEnderecoViaCep($data);
        $cliente->update($data);
        return response()->json($cliente);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json($cliente);
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        $cliente->delete();
        return response()->json(null, 204);
    }

    public function verificaDocumento($documento, $id = null): JsonResponse
    {
        $documentoLimpo = preg_replace('/\D/', '', $documento);

        $query = Cliente::where('documento', $documentoLimpo);
        if ($id) {
            $query->where('id', '!=', $id);
        }

        $existe = $query->exists();

        return response()->json(['existe' => $existe]);
    }

}
