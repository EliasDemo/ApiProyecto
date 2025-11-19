<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VmProyectoCiclo extends Model
{
    use HasFactory;

    protected $table = 'vm_proyecto_ciclos';

    protected $fillable = [
        'proyecto_id',
        'ep_sede_id',
        'periodo_id',
        'nivel',       // 1..10
    ];

    protected $casts = [
        'proyecto_id' => 'integer',
        'ep_sede_id'  => 'integer',
        'periodo_id'  => 'integer',
        'nivel'       => 'integer',
    ];

    public function proyecto()
    {
        return $this->belongsTo(VmProyecto::class, 'proyecto_id');
    }

    public function epSede()
    {
        return $this->belongsTo(EpSede::class, 'ep_sede_id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_id');
    }

    /** Sesiones que utilizan este ciclo */
    public function sesiones()
    {
        return $this->belongsToMany(
            VmSesion::class,
            'vm_sesion_ciclos',
            'proyecto_ciclo_id',
            'sesion_id'
        );
    }
}
