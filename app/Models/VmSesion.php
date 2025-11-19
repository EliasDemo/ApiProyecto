<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VmSesion extends Model
{
    use HasFactory;

    protected $table = 'vm_sesiones';

    protected $fillable = [
        'sessionable_id',
        'sessionable_type',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'estado',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    protected static function booted()
    {
        static::saved(function (self $s) {
            app(\App\Services\Vm\EstadoService::class)->recalcOwner($s->sessionable);
        });
        static::deleted(function (self $s) {
            app(\App\Services\Vm\EstadoService::class)->recalcOwner($s->sessionable);
        });
    }

    /* =====================
     | Relaciones
     |=====================*/

    public function sessionable()
    {
        // VmProceso o VmEvento
        return $this->morphTo();
    }

    public function asistencias()
    {
        return $this->hasMany(VmAsistencia::class, 'sesion_id');
    }

    public function qrTokens()
    {
        return $this->hasMany(VmQrToken::class, 'sesion_id');
    }

    public function registrosHoras()
    {
        return $this->hasMany(RegistroHora::class, 'sesion_id');
    }

    /** Multiciclo: ciclos a los que aplica esta sesión */
    public function ciclos()
    {
        return $this->belongsToMany(
            VmProyectoCiclo::class,
            'vm_sesion_ciclos',
            'sesion_id',
            'proyecto_ciclo_id'
        );
    }

    /* =====================
     | Scopes útiles
     |=====================*/

    public function scopeEnFecha($q, $fecha)
    {
        return $q->whereDate('fecha', $fecha);
    }

    public function scopeEntreHoras($q, string $desde, string $hasta)
    {
        return $q->whereTime('hora_inicio', '>=', $desde)
                 ->whereTime('hora_fin', '<=', $hasta);
    }

    public function scopeEnCurso($q)
    {
        return $q->where('estado', 'EN_CURSO');
    }

    /** Filtra sesiones que aplican a un nivel concreto del proyecto */
    public function scopeParaNivel($q, int $nivel)
    {
        return $q->whereHas('ciclos', fn($qq) => $qq->where('nivel', $nivel));
    }

    /* =====================
     | Mutators (normalizan HH:mm -> HH:mm:ss al guardar)
     |=====================*/

    public function setHoraInicioAttribute($value): void
    {
        $this->attributes['hora_inicio'] = $this->normalizeToHms($value);
    }

    public function setHoraFinAttribute($value): void
    {
        $this->attributes['hora_fin'] = $this->normalizeToHms($value);
    }

    protected function normalizeToHms($value): ?string
    {
        if ($value === null || $value === '') return null;
        $s = (string) $value;

        // 8:00 -> 08:00
        if (preg_match('/^\d{1}:\d{2}$/', $s)) {
            $s = '0' . $s;
        }
        // HH:mm:ss (ok)
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) {
            return $s;
        }
        // HH:mm -> HH:mm:00
        if (preg_match('/^\d{2}:\d{2}$/', $s)) {
            return $s . ':00';
        }
        // Intento genérico
        try {
            return Carbon::parse($s)->format('H:i:s');
        } catch (\Throwable $e) {
            return $s; // deja tal cual si no se pudo parsear (evita romper)
        }
    }

    /* =====================
     | Helpers / Accessors
     |=====================*/

    /** Devuelve duración en minutos (tolera HH:mm o HH:mm:ss). */
    public function getDuracionMinutosAttribute(): ?int
    {
        $ini = $this->parseTimeFlexible($this->hora_inicio);
        $fin = $this->parseTimeFlexible($this->hora_fin);
        if (!$ini || !$fin) return null;
        return $ini->diffInMinutes($fin, false);
    }

    /** HH:mm (útil para exponer al front sin segundos). */
    public function getHoraInicioHhmmAttribute(): ?string
    {
        $c = $this->parseTimeFlexible($this->hora_inicio);
        return $c ? $c->format('H:i') : null;
    }

    /** HH:mm (útil para exponer al front sin segundos). */
    public function getHoraFinHhmmAttribute(): ?string
    {
        $c = $this->parseTimeFlexible($this->hora_fin);
        return $c ? $c->format('H:i') : null;
    }

    /** Devuelve [niveles] de la sesión (derivados de los ciclos). */
    public function getNivelesAttribute(): array
    {
        if (!$this->relationLoaded('ciclos')) {
            $this->load('ciclos');
        }
        return $this->ciclos->pluck('nivel')->values()->all();
    }

    /** Parser flexible para HH:mm o HH:mm:ss (o strings parseables por Carbon). */
    protected function parseTimeFlexible($value): ?Carbon
    {
        if ($value === null || $value === '') return null;
        $s = (string) $value;

        try {
            // formatos más comunes primero
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) {
                return Carbon::createFromFormat('H:i:s', $s);
            }
            if (preg_match('/^\d{2}:\d{2}$/', $s)) {
                return Carbon::createFromFormat('H:i', $s);
            }
            if (preg_match('/^\d{1}:\d{2}$/', $s)) { // 8:00
                return Carbon::createFromFormat('H:i', '0' . $s);
            }
            // fallback genérico
            return Carbon::parse($s);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
