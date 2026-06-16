<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Estoque extends Model
{
    protected $table = 'estoque';

    protected $fillable = [
        'id_variacao',
        'id_deposito',
        'quantidade',
        'data_entrada_estoque_atual',
        'ultima_venda_em',
        'corredor',
        'prateleira',
        'nivel',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'data_entrada_estoque_atual' => 'datetime',
        'ultima_venda_em' => 'datetime',
    ];

    /** @return BelongsTo<ProdutoVariacao,Estoque> */
    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao')->withDefault();
    }

    /** @return BelongsTo<Deposito,Estoque> */
    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito')->withDefault();
    }

    /** @return HasOne<LocalizacaoEstoque> */
    public function localizacao(): HasOne
    {
        return $this->hasOne(LocalizacaoEstoque::class, 'estoque_id');
    }

    public function quantidadeReservadaAberta(): int
    {
        $query = DB::table('estoque_reservas')
            ->where('id_variacao', $this->id_variacao)
            ->where('status', 'ativa')
            ->where(function ($query) {
                $query->whereNull('data_expira')
                    ->orWhere('data_expira', '>', now());
            });

        if ($this->id_deposito === null) {
            $query->whereNull('id_deposito');
        } else {
            $query->where('id_deposito', $this->id_deposito);
        }

        return (int) $query->sum(DB::raw('CASE WHEN quantidade > quantidade_consumida THEN quantidade - quantidade_consumida ELSE 0 END'));
    }

    public function quantidadeDisponivelComReservas(): int
    {
        return max(0, (int) $this->quantidade - $this->quantidadeReservadaAberta());
    }

}
