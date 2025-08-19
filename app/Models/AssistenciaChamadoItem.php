<?php

namespace App\Models;

use App\Enums\AprovacaoOrcamento;
use App\Enums\AssistenciaStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Item de um chamado (cada produto/variação com defeito).
 */
class AssistenciaChamadoItem extends Model
{
    protected $table = 'assistencia_chamado_itens';

    protected $fillable = [
        'chamado_id','produto_id','variacao_id','numero_serie','lote',
        'defeito_id','descricao_defeito_livre','status_item',
        'pedido_id','pedido_item_id','consignacao_id','consignacao_item_id',
        'deposito_origem_id','assistencia_id','deposito_assistencia_id',
        'rastreio_envio','rastreio_retorno','data_envio','data_retorno',
        'valor_orcado','aprovacao','data_aprovacao','observacoes'
    ];

    protected $casts = [
        'data_envio' => 'date',
        'data_retorno' => 'date',
        'data_aprovacao' => 'date',
        'valor_orcado' => 'decimal:2',
        'status_item' => AssistenciaStatus::class,
        'aprovacao' => AprovacaoOrcamento::class,
    ];

    /** --- RELAÇÕES --- */
    public function chamado()     { return $this->belongsTo(AssistenciaChamado::class, 'chamado_id'); }
    public function produto()     { return $this->belongsTo(Produto::class, 'produto_id'); }
    public function variacao()    { return $this->belongsTo(ProdutoVariacao::class, 'variacao_id'); }
    public function defeito()     { return $this->belongsTo(AssistenciaDefeito::class, 'defeito_id'); }

    public function pedido()      { return $this->belongsTo(Pedido::class, 'pedido_id'); }
    public function pedidoItem()  { return $this->belongsTo(PedidoItem::class, 'pedido_item_id'); }

    public function consignacao()     { return $this->belongsTo(Consignacao::class, 'consignacao_id'); }
    public function consignacaoItem() { return $this->belongsTo(ConsignacaoItem::class, 'consignacao_item_id'); }

    public function depositoOrigem()      { return $this->belongsTo(Deposito::class, 'deposito_origem_id'); }
    public function depositoAssistencia() { return $this->belongsTo(Deposito::class, 'deposito_assistencia_id'); }

    public function assistencia() { return $this->belongsTo(Assistencia::class, 'assistencia_id'); }

    public function arquivos()
    {
        return $this->hasMany(AssistenciaArquivo::class, 'item_id');
    }

    public function logs()
    {
        return $this->hasMany(AssistenciaChamadoLog::class, 'item_id')->latest();
    }
}
