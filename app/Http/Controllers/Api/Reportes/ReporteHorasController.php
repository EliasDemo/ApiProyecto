<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reportes\RegistroHoraResource;
use App\Models\RegistroHora;
use App\Models\PeriodoAcademico;
use App\Models\ExpedienteAcademico;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReporteHorasController extends Controller
{
    /**
     * Dado un alias o FQCN, devuelve [alias, class] normalizados.
     * Siempre intenta resolver a alias configurado en morphMap; si no encuentra, usa heurística por nombre.
     */
    private function normalizeMorphType(string $type): array
    {
        // 1) Si es alias, obtengo la clase desde el morphMap
        $class = Relation::getMorphedModel($type) ?? null;

        // 2) Si no hay clase, puede que $type ya sea FQCN; lo uso tal cual.
        if (!$class && class_exists($type)) {
            $class = $type;
        }

        // 3) Intento obtener el alias a partir del morphMap actual
        $map = [];
        if (method_exists(Relation::class, 'morphMap')) {
            // Laravel 8–10: Relation::morphMap() devuelve el map actual (o vacío)
            $map = Relation::morphMap() ?? [];
        } elseif (method_exists(Relation::class, 'getMorphMap')) {
            // Algunas instalaciones usan getMorphMap()
            $map = Relation::getMorphMap() ?? [];
        }

        $alias = null;
        if ($class && $map) {
            $alias = array_search($class, $map, true) ?: null;
        }

        // 4) Heurística de fallback para alias comunes
        if (!$alias && $class) {
            if (str_contains($class, 'VmProyecto')) {
                $alias = 'vm_proyecto';
            } elseif (str_contains($class, 'VmEvento')) {
                $alias = 'vm_evento';
            }
        }

        // 5) Si tampoco, y el original ya parece un alias, úsalo
        if (!$alias && !class_exists($type)) {
            $alias = $type;
        }

        // 6) Último fallback: usa lo que tengas
        $alias = $alias ?: $type;
        $class = $class ?: $type;

        return [$alias, $class];
    }

    /**
     * GET /api/reportes/horas/mias
     * Resumen + historial del usuario autenticado.
     */
    public function miReporte(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(EpScopeService::PERM_VIEW_EXPEDIENTE)) {
            return response()->json(['ok' => false, 'message' => 'NO_AUTORIZADO'], 403);
        }

        $expedienteId = EpScopeService::expedienteId($user->id);
        if (!$expedienteId) {
            return response()->json(['ok' => false, 'message' => 'EXPEDIENTE_NO_ENCONTRADO'], 404);
        }

        return $this->buildReporte($request, $expedienteId);
    }

    /**
     * GET /api/reportes/horas/expedientes/{expediente}
     * Resumen + historial de un expediente (para encargados/coordinadores).
     */
    public function expedienteReporte(Request $request, int $expediente): JsonResponse
    {
        $user = $request->user();
        $exp  = ExpedienteAcademico::find($expediente);

        if (!$user || !$exp) {
            return response()->json(['ok' => false, 'message' => 'NO_AUTORIZADO_O_EXPEDIENTE'], 403);
        }

        // Autorización: gestiona el EP_SEDE del expediente o tiene permisos mayores
        $can =
            EpScopeService::userManagesEpSede($user->id, $exp->ep_sede_id) ||
            $user->can(EpScopeService::PERM_MANAGE_SEDE) ||
            $user->can(EpScopeService::PERM_MANAGE_FACULTAD);

        if (!$can) {
            return response()->json(['ok' => false, 'message' => 'NO_AUTORIZADO'], 403);
        }

        return $this->buildReporte($request, $expediente);
    }

    /**
     * Construye el reporte (resumen + historial) con filtros comunes.
     *
     * Filtros:
     * - periodo_id
     * - desde (YYYY-MM-DD)
     * - hasta (YYYY-MM-DD)
     * - estado (por defecto: APROBADO; si estado="*" no filtra)
     * - tipo (vinculable_type: vm_proyecto | vm_evento | FQCN)
     * - vinculable_id (id del proyecto/evento)
     * - q (busca en actividad)
     * - per_page (historial, default 15)
     */
    protected function buildReporte(Request $request, int $expedienteId): JsonResponse
    {
        $q = RegistroHora::query()
            ->where('expediente_id', $expedienteId);

        // Filtros base
        if ($request->filled('periodo_id')) {
            $q->where('periodo_id', (int) $request->get('periodo_id'));
        }
        if ($request->filled('desde')) {
            $q->whereDate('fecha', '>=', $request->get('desde'));
        }
        if ($request->filled('hasta')) {
            $q->whereDate('fecha', '<=', $request->get('hasta'));
        }

        // Por defecto solo aprobados; si quieres todos, envía ?estado=*
        $estado = $request->get('estado');
        if ($estado && $estado !== '*') {
            $q->where('estado', $estado);
        } elseif (!$estado) {
            $q->where('estado', 'APROBADO');
        }

        // Filtro por tipo (acepta alias y/o FQCN)
        if ($request->filled('tipo')) {
            [$alias, $class] = $this->normalizeMorphType((string) $request->get('tipo'));
            // whereIn para cubrir registros viejos con FQCN y nuevos con alias
            $q->whereIn('vinculable_type', [$alias, $class]);
        }

        if ($request->filled('vinculable_id')) {
            $q->where('vinculable_id', (int) $request->get('vinculable_id'));
        }

        if ($request->filled('q')) {
            $q->where('actividad', 'like', '%' . trim((string) $request->get('q')) . '%');
        }

        // ===== Resumen total y desgloses =====
        $base = clone $q;

        // Total minutos
        $totalMin = (int) (clone $base)->sum('minutos');

        // ---- Por período ----
        $porPeriodoRows = (clone $base)
            ->selectRaw('periodo_id, SUM(minutos) AS minutos')
            ->groupBy('periodo_id')
            ->get();

        $periodos = PeriodoAcademico::whereIn(
            'id',
            $porPeriodoRows->pluck('periodo_id')->filter()->unique()
        )
            ->get(['id', 'codigo'])
            ->keyBy('id');

        $porPeriodo = $porPeriodoRows->map(function ($r) use ($periodos) {
            $codigo = optional($periodos->get($r->periodo_id))->codigo;

            return [
                'periodo_id' => $r->periodo_id,
                'codigo'     => $codigo,
                'minutos'    => (int) $r->minutos,
                'horas'      => round($r->minutos / 60, 2),
            ];
        })->values();

        // ---- Por proyecto/evento (vinculable) ----
        $porVinculoRows = (clone $base)
            ->selectRaw('vinculable_type, vinculable_id, SUM(minutos) AS minutos')
            ->groupBy('vinculable_type', 'vinculable_id')
            ->get();

        $porVinculo = collect();

        // Normalizamos siempre a ALIAS (vm_proyecto / vm_evento / otro)
        $porVinculoRows->groupBy('vinculable_type')->each(function ($rows, $type) use (&$porVinculo) {
            // Resuelve class y alias normalizados
            [$alias, $class] = $this->normalizeMorphType($type);

            // Etiqueta humana simple
            $tipoLabel = match ($alias) {
                'vm_proyecto' => 'PROYECTO',
                'vm_evento'   => 'EVENTO',
                default       => strtoupper($alias),
            };

            // Si no existe la clase, de todas formas devolvemos usando el alias
            if (!class_exists($class)) {
                foreach ($rows as $r) {
                    $porVinculo->push([
                        'tipo'       => $alias,      // vm_proyecto / vm_evento / etc.
                        'tipo_label' => $tipoLabel,  // PROYECTO / EVENTO / ...
                        'id'         => (int) $r->vinculable_id,
                        'titulo'     => null,
                        'minutos'    => (int) $r->minutos,
                        'horas'      => round($r->minutos / 60, 2),

                        // estos quedan en null si no sabemos nada más
                        'minutos_requeridos' => null,
                        'horas_requeridas'   => null,
                        'cumplido'           => null,
                    ]);
                }
                return;
            }

            $ids    = $rows->pluck('vinculable_id')->unique()->all();
            $models = $class::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($rows as $r) {
                $m      = $models->get($r->vinculable_id);
                $titulo = $m->titulo ?? ($m->nombre ?? ($m->codigo ?? null));

                // Cálculo de cumplimiento (solo aplica a proyectos con horas mínimas definidas)
                $minutosRequeridos = null;
                $horasRequeridas   = null;
                $cumplido          = null;

                if ($m instanceof \App\Models\VmProyecto && $m->horas_minimas_participante) {
                    $minutosRequeridos = (int) $m->horas_minimas_participante * 60;
                    $horasRequeridas   = (int) $m->horas_minimas_participante;
                    $cumplido          = ((int) $r->minutos) >= $minutosRequeridos;
                }

                $porVinculo->push([
                    'tipo'                => $alias,      // vm_proyecto / vm_evento
                    'tipo_label'          => $tipoLabel,  // PROYECTO / EVENTO
                    'id'                  => (int) $r->vinculable_id,
                    'titulo'              => $titulo,
                    'minutos'             => (int) $r->minutos,
                    'horas'               => round($r->minutos / 60, 2),
                    'minutos_requeridos'  => $minutosRequeridos,
                    'horas_requeridas'    => $horasRequeridas,
                    'cumplido'            => $cumplido,
                ]);
            }
        });

        // ===== Historial paginado =====
        $perPage = max(1, min((int) $request->get('per_page', 15), 100));

        $historial = $q
            ->with([
                'periodo:id,codigo',
                'vinculable' => function (MorphTo $morphTo) {
                    $morphTo->constrain([
                        \App\Models\VmProyecto::class => function ($q) {
                            $q->select(
                                'id',
                                'codigo',
                                'titulo',
                                'descripcion',
                                'tipo',
                                'modalidad',
                                'estado',
                                'horas_planificadas',
                                'horas_minimas_participante'
                            );
                        },
                        \App\Models\VmEvento::class => function ($q) {
                            $q->select('id', 'codigo', 'titulo', 'estado');
                        },
                    ]);
                },
            ])
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'ok'   => true,
            'data' => [
                'resumen' => [
                    'total_minutos' => $totalMin,
                    'total_horas'   => round($totalMin / 60, 2),
                    'por_periodo'   => $porPeriodo,
                    'por_vinculo'   => $porVinculo->values(),
                ],
                // Aquí cada item del historial ya trae:
                //  - periodo.id / periodo.codigo
                //  - vinculable.tipo = vm_proyecto | vm_evento
                //  - vinculable.codigo / titulo / etc.
                'historial' => RegistroHoraResource::collection($historial),
            ],
            'meta' => [
                'current_page' => $historial->currentPage(),
                'per_page'     => $historial->perPage(),
                'total'        => $historial->total(),
                'last_page'    => $historial->lastPage(),
            ],
        ]);
    }
}
