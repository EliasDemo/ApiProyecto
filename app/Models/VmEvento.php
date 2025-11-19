<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class VmEvento extends Model
{
    use HasFactory;

    protected $table = 'vm_eventos';

    /**
     * Alias ↔ Clase para el target polimórfico.
     */
    public const TARGET_ALIAS_TO_CLASS = [
        'ep_sede'  => \App\Models\EpSede::class,
        'sede'     => \App\Models\Sede::class,
        'facultad' => \App\Models\Facultad::class,
    ];

    public const TARGET_CLASS_TO_ALIAS = [
        \App\Models\EpSede::class   => 'ep_sede',
        \App\Models\Sede::class     => 'sede',
        \App\Models\Facultad::class => 'facultad',
    ];

    protected $fillable = [
        'periodo_id',

        // Polimórfico
        'targetable_id',
        'targetable_type',
        // aliases "virtuales"
        'target_id',
        'target_type',

        // Categoría
        'categoria_evento_id',

        // Datos principales
        'codigo',
        'titulo',
        'subtitulo',
        'descripcion_corta',
        'descripcion_larga',

        // Presentación
        'modalidad',
        'lugar_detallado',
        'url_imagen_portada',
        'url_enlace_virtual',

        // Estado / reglas
        'estado',
        'requiere_inscripcion',
        'cupo_maximo',
        'inscripcion_desde',
        'inscripcion_hasta',
    ];

    protected $casts = [
        'requiere_inscripcion' => 'boolean',
        'cupo_maximo'          => 'integer',
        'inscripcion_desde'    => 'date:Y-m-d',
        'inscripcion_hasta'    => 'date:Y-m-d',
    ];

    /**
     * Al serializar a JSON, expón target_id / target_type (alias) y
     * oculta los campos internos de la relación polimórfica.
     */
    protected $appends = ['target_id', 'target_type'];
    protected $hidden  = ['targetable_id', 'targetable_type'];

    /* =====================
     | Accessors / Mutators
     |=====================*/

    /**
     * target_type (alias) ↔ targetable_type (clase)
     */
    protected function targetType(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $class = $attributes['targetable_type'] ?? null;
                return self::TARGET_CLASS_TO_ALIAS[$class] ?? $class;
            },
            set: function ($value) {
                // Permite enviar alias ('ep_sede', 'sede', 'facultad') o FQCN
                $class = self::TARGET_ALIAS_TO_CLASS[$value] ?? $value;
                return ['targetable_type' => $class];
            }
        );
    }

    /**
     * target_id (alias) ↔ targetable_id
     */
    protected function targetId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['targetable_id'] ?? null,
            set: fn ($value) => ['targetable_id' => $value]
        );
    }

    /**
     * Atributo virtual ep_sede_id.
     *
     * No existe columna en la tabla; se deriva de targetable_type/targetable_id.
     * Si algún día agregas la columna real ep_sede_id, también la soporta.
     */
    protected function epSedeId(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // 1) Si existiera columna real ep_sede_id, úsala
                if (array_key_exists('ep_sede_id', $attributes) && !is_null($attributes['ep_sede_id'])) {
                    return (int) $attributes['ep_sede_id'];
                }

                // 2) Derivar desde targetable_*
                $type = $attributes['targetable_type'] ?? null;
                $id   = $attributes['targetable_id'] ?? null;

                if (!$type || !$id) {
                    return null;
                }

                // Caso típico: guardas alias 'ep_sede'
                if ($type === 'ep_sede' || $type === \App\Models\EpSede::class) {
                    return (int) $id;
                }

                // Si apunta a Sede que tiene ep_sede_id
                if ($type === \App\Models\Sede::class) {
                    $sede = \App\Models\Sede::find($id);
                    return $sede?->ep_sede_id ? (int) $sede->ep_sede_id : null;
                }

                // Si apunta a Facultad que tiene ep_sede_id
                if ($type === \App\Models\Facultad::class) {
                    $fac = \App\Models\Facultad::find($id);
                    return $fac?->ep_sede_id ? (int) $fac->ep_sede_id : null;
                }

                return null;
            }
        );
    }

    /* =====================
     | Relaciones
     |=====================*/

    public function periodo()
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_id');
    }

    // Alcance polimórfico (Sede/Facultad/EpSede)
    public function targetable()
    {
        return $this->morphTo();
    }

    // Categoría del evento
    public function categoria()
    {
        return $this->belongsTo(VmCategoriaEvento::class, 'categoria_evento_id');
    }

    // Sesiones polimórficas (el evento "tiene" sesiones)
    public function sesiones()
    {
        return $this->morphMany(VmSesion::class, 'sessionable');
    }

    // Participaciones polimórficas
    public function participaciones()
    {
        return $this->morphMany(VmParticipacion::class, 'participable');
    }

    // Certificados polimórficos
    public function certificados()
    {
        return $this->morphMany(Certificado::class, 'certificable');
    }

    // Imágenes polimórficas
    public function imagenes()
    {
        return $this->morphMany(Imagen::class, 'imageable');
    }

    // Registro de horas (si lo vinculas como 'vinculable' a un evento)
    public function registrosHoras()
    {
        return $this->morphMany(RegistroHora::class, 'vinculable');
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

    public function scopeDelPeriodo($q, int $id)
    {
        return $q->where('periodo_id', $id);
    }

    // NOTA: ya no hay scopes por fecha aquí porque la agenda vive en vm_sesiones.
}
