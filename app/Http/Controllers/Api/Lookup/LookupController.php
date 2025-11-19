<?php

namespace App\Http\Controllers\Api\Lookup;

use App\Http\Controllers\Controller;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    /**
     * GET /api/lookups/ep-sedes
     * Params:
     *  - q: string
     *  - limit: int
     *  - solo_mias: bool (default true)
     *  - solo_activas: bool (default true)
     *  - solo_staff: bool (default true)
     *  - roles: "COORDINADOR,ENCARGADO" (si solo_staff=1; opcional)
     */
    public function epSedes(Request $req)
    {
        $user  = $req->user();
        $q     = trim((string) $req->query('q', ''));
        $limit = (int) $req->query('limit', 50);

        $soloMias    = $req->boolean('solo_mias', true);
        $soloActivas = $req->boolean('solo_activas', true);
        $soloStaff   = $req->boolean('solo_staff', true);
        $rolesParam  = trim((string) $req->query('roles', 'COORDINADOR,ENCARGADO'));
        $roles       = array_values(array_filter(array_map('trim', explode(',', strtoupper($rolesParam)))));

        $query = EpSede::query()
            ->with(['escuelaProfesional:id,nombre', 'sede:id,nombre']);

        if ($soloMias && $user) {
            // Filtra por expedientes del usuario
            $query->whereIn('id', function ($sub) use ($user, $soloActivas, $soloStaff, $roles) {
                $sub->select('ep_sede_id')
                    ->from('expedientes_academicos')
                    ->where('user_id', $user->id);

                if ($soloActivas) {
                    $sub->where('estado', 'ACTIVO');
                }
                if ($soloStaff) {
                    $allowed = $roles ?: ['COORDINADOR', 'ENCARGADO'];
                    $sub->whereIn('rol', $allowed);
                }
            });
        }

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->whereHas('escuelaProfesional', fn ($w) => $w->where('nombre', 'like', "%{$q}%"))
                   ->orWhereHas('sede', fn ($w) => $w->where('nombre', 'like', "%{$q}%"));
            });
        }

        $items = $query
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function ($ep) {
                $label = "{$ep->escuelaProfesional?->nombre} — {$ep->sede?->nombre}";
                return [
                    'id'      => (int) $ep->id,
                    'label'   => $label, // "escuela — sede"
                    'escuela' => $ep->escuelaProfesional?->nombre,
                    'sede'    => $ep->sede?->nombre,
                ];
            });

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * GET /api/lookups/periodos
     * Params:
     *  - q: string
     *  - limit: int
     *  - solo_activos: bool (filtra estado EN_CURSO)
     */
    public function periodos(Request $req)
    {
        $q           = trim((string) $req->query('q', ''));
        $soloActivos = $req->boolean('solo_activos', false);
        $limit       = (int) $req->query('limit', 50);

        $items = PeriodoAcademico::query()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where('codigo', 'like', "%{$q}%")
                   ->orWhere('anio', 'like', "%{$q}%")
                   ->orWhere('ciclo', 'like', "%{$q}%");
            })
            ->when($soloActivos, fn ($qq) => $qq->where('estado', 'EN_CURSO'))
            ->orderByDesc('fecha_inicio')
            ->limit($limit)
            ->get()
            ->map(function (PeriodoAcademico $p) {
                // Formateo seguro de fechas (evita warnings del analizador estático)
                $fi = $p->fecha_inicio instanceof \DateTimeInterface
                    ? $p->fecha_inicio->format('Y-m-d')
                    : (string) $p->fecha_inicio;

                $ff = $p->fecha_fin instanceof \DateTimeInterface
                    ? $p->fecha_fin->format('Y-m-d')
                    : (string) $p->fecha_fin;

                return [
                    'id'           => (int) $p->id,
                    'label'        => "{$p->anio} - {$p->ciclo}", // "año - ciclo"
                    'anio'         => (int) $p->anio,
                    'ciclo'        => (int) $p->ciclo,
                    'estado'       => (string) $p->estado,        // PLANIFICADO | EN_CURSO | CERRADO
                    'es_actual'    => (bool) $p->es_actual,
                    'fecha_inicio' => $fi,
                    'fecha_fin'    => $ff,
                ];
            });

        return response()->json(['ok' => true, 'items' => $items]);
    }
}
