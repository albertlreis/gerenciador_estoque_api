<?php

namespace App\Http\Resources;

use App\Models\ParceiroContato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ParceiroResource extends JsonResource
{
    public function toArray($request): array
    {
        $contatos = $this->resolveContatos($request);

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'tipo' => $this->tipo,
            'documento' => $this->documento,
            'email' => $this->resolveContatoRoot($contatos, 'email') ?? $this->email,
            'telefone' => $this->resolveContatoRoot($contatos, 'telefone') ?? $this->telefone,
            'consultor_nome' => $this->consultor_nome,
            'nivel_fidelidade' => $this->nivel_fidelidade,
            'endereco' => $this->endereco,
            'status' => (int) ($this->status ?? 1),
            'observacoes' => $this->observacoes,
            'contatos' => $contatos->map(fn (ParceiroContato $contato) => [
                'id' => $contato->id,
                'tipo' => $contato->tipo,
                'valor' => $contato->valor,
                'valor_e164' => $contato->valor_e164,
                'rotulo' => $contato->rotulo,
                'principal' => (bool) $contato->principal,
                'observacoes' => $contato->observacoes,
                'created_at' => optional($contato->created_at)->toIso8601String(),
                'updated_at' => optional($contato->updated_at)->toIso8601String(),
                'deleted_at' => optional($contato->deleted_at)->toIso8601String(),
            ])->values()->all(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)->toIso8601String(),
        ];
    }

    private function resolveContatos(Request $request): Collection
    {
        $loaded = $this->relationLoaded('contatos')
            ? $this->contatos
            : $this->contatos()->orderByDesc('principal')->orderBy('id')->get();

        return $loaded
            ->whereNull('deleted_at')
            ->sortByDesc(fn (ParceiroContato $c) => (int) $c->principal)
            ->sortBy('id')
            ->values();
    }

    private function resolveContatoRoot(Collection $contatos, string $tipo): ?string
    {
        $porTipo = $contatos->where('tipo', $tipo)->values();
        if ($porTipo->isEmpty()) {
            return null;
        }

        $principal = $porTipo->firstWhere('principal', true);
        if ($principal instanceof ParceiroContato) {
            return $principal->valor;
        }

        return $porTipo->first()?->valor;
    }
}
