<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DespesaRecorrente */
class DespesaRecorrenteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,

            'fornecedor_id' => $this->fornecedor_id,
            'descricao' => $this->descricao,
            'numero_documento' => $this->numero_documento,

            'centro_custo' => $this->centro_custo,
            'categoria' => $this->categoria,

            'valor_bruto' => $this->valor_bruto,
            'desconto' => $this->desconto,
            'juros' => $this->juros,
            'multa' => $this->multa,

            'tipo' => $this->tipo,
            'frequencia' => $this->frequencia,
            'intervalo' => $this->intervalo,
            'dia_vencimento' => $this->dia_vencimento,
            'mes_vencimento' => $this->mes_vencimento,

            'data_inicio' => optional($this->data_inicio)->format('Y-m-d'),
            'data_fim' => optional($this->data_fim)->format('Y-m-d'),

            'criar_conta_pagar_auto' => (bool) $this->criar_conta_pagar_auto,
            'dias_antecedencia' => $this->dias_antecedencia,
            'status' => $this->status,

            'observacoes' => $this->observacoes,

            'fornecedor' => $this->whenLoaded('fornecedor', function () {
                return [
                    'id' => $this->fornecedor?->id,
                    'nome' => $this->fornecedor?->nome ?? null,
                ];
            }),

            'usuario' => $this->whenLoaded('usuario', function () {
                return $this->formatUsuario($this->usuario);
            }),

            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
            'deleted_at' => optional($this->deleted_at)?->toISOString(),
        ];
    }

    private function formatUsuario($usuario): ?array
    {
        if (!$usuario) return null;

        // Ajuste os campos abaixo conforme sua tabela acesso_usuarios
        // (ex: nome, nome_completo, email, usuario, etc.)
        return [
            'id' => $usuario->id ?? null,
            'nome' => $usuario->nome ?? ($usuario->nome_completo ?? null),
            'email' => $usuario->email ?? null,
        ];
    }
}
