<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VmSesionCiclo extends Model
{
    use HasFactory;

    protected $table = 'vm_sesion_ciclos';

    protected $fillable = [
        'sesion_id',
        'proyecto_ciclo_id',
    ];

    public function sesion()
    {
        return $this->belongsTo(VmSesion::class, 'sesion_id');
    }

    public function proyectoCiclo()
    {
        return $this->belongsTo(VmProyectoCiclo::class, 'proyecto_ciclo_id');
    }
}
