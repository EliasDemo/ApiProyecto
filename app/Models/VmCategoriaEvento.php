<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VmCategoriaEvento extends Model
{
    use HasFactory;

    protected $table = 'vm_categorias_evento';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function eventos()
    {
        return $this->hasMany(VmEvento::class, 'categoria_evento_id');
    }
}
