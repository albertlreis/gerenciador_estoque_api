<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OutletMotivo extends Model{
    protected $table='outlet_motivos';
    protected $fillable=['slug','nome','ativo'];
    protected $casts=['ativo'=>'boolean'];
}
