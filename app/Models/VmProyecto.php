<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VmProyecto extends Model
{
    use HasFactory;

    protected $table = 'vm_proyectos';

    protected $fillable = [
        'ep_sede_id',
        'periodo_id',
        'codigo',
        'titulo',
        'descripcion',
        'tipo',
        'modalidad',
        'estado',
        // 👇 SIN 'nivel' (multiciclo va en vm_proyecto_ciclos)
        'horas_planificadas',
        'horas_minimas_participante',
    ];

    protected $casts = [
        'horas_planificadas'         => 'integer',
        'horas_minimas_participante' => 'integer',
    ];

    /* =====================
     | Relaciones
     |=====================*/

    public function epSede()
    {
        return $this->belongsTo(EpSede::class, 'ep_sede_id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_id');
    }

    public function procesos()
    {
        return $this->hasMany(VmProceso::class, 'proyecto_id');
    }

    public function participaciones()
    {
        return $this->morphMany(VmParticipacion::class, 'participable');
    }

    public function certificados()
    {
        return $this->morphMany(Certificado::class, 'certificable');
    }

    public function imagenes()
    {
        return $this->morphMany(Imagen::class, 'imageable');
    }

    public function registrosHoras()
    {
        return $this->morphMany(RegistroHora::class, 'vinculable');
    }

    /** Multiciclo */
    public function ciclos()
    {
        return $this->hasMany(VmProyectoCiclo::class, 'proyecto_id')->orderBy('nivel');
    }

    /* =====================
     | Hooks
     |=====================*/

    protected static function booted()
    {
        static::deleting(function (self $proyecto) {
            // Si tu FK vm_procesos.proyecto_id tiene onDelete('cascade'),
            // puedes quitar este bloque.
            $proyecto->loadMissing('procesos');
            foreach ($proyecto->procesos as $proceso) {
                $proceso->delete();
            }
        });
    }

    /* =====================
     | Scopes útiles
     |=====================*/

    public function scopeEnCurso($q)
    {
        return $q->where('estado', 'EN_CURSO');
    }

    public function scopePlanificados($q)
    {
        return $q->where('estado', 'PLANIFICADO');
    }

    public function scopeDelPeriodo($q, int $periodoId)
    {
        return $q->where('periodo_id', $periodoId);
    }

    public function scopeDeEpSede($q, int $epSedeId)
    {
        return $q->where('ep_sede_id', $epSedeId);
    }

    /**
     * Compat del antiguo scopeDelNivel(n):
     * ahora filtra por relación ciclos (no columna).
     */
    public function scopeDelNivel($q, int $nivel)
    {
        return $q->whereHas('ciclos', fn($qq) => $qq->where('nivel', $nivel));
    }

    /** Alias explícito */
    public function scopeConNivel($q, int $nivel)
    {
        return $q->whereHas('ciclos', fn($qq) => $qq->where('nivel', $nivel));
    }

    /* =====================
     | Accessors útiles
     |=====================*/

    /** Devuelve [1,2,3...] con los niveles del proyecto */
    public function getNivelesAttribute(): array
    {
        if (!$this->relationLoaded('ciclos')) {
            $this->load('ciclos');
        }
        return $this->ciclos->pluck('nivel')->values()->all();
    }

    /* =====================
     | Reglas de negocio
     |=====================*/

    /**
     * editable/eliminable si:
     *  - estado === PLANIFICADO
     *  - no hay sesiones pasadas o ya iniciadas hoy
     */
    public function isEditable(): bool
    {
        if ($this->estado !== 'PLANIFICADO') {
            return false;
        }

        $today = Carbon::today()->toDateString();
        $now   = Carbon::now()->format('H:i:s');

        $yaInicio = $this->procesos()
            ->whereHas('sesiones', function ($q) use ($today, $now) {
                $q->whereDate('fecha', '<', $today)
                  ->orWhere(function ($qq) use ($today, $now) {
                      $qq->whereDate('fecha', $today)
                         ->where('hora_inicio', '<=', $now);
                  });
            })
            ->exists();

        return !$yaInicio;
    }
}
