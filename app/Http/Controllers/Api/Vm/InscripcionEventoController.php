<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Models\VmEvento;
use App\Models\VmParticipacion;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InscripcionEventoController extends Controller
{
    /**
     * POST /api/vm/eventos/{evento}/inscribirse
     * - Lógica tipo LIBRE: expediente ACTIVO en misma EP_SEDE
     * - Respeta ventana de inscripción + cupo_maximo
     */
    public function inscribirEvento(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        // Evento vigente
        if (!in_array($evento->estado, ['PLANIFICADO', 'EN_CURSO'])) {
            return $this->fail('EVENT_NOT_ACTIVE', 'El evento no admite inscripciones.', 422, [
                'estado' => $evento->estado,
            ]);
        }

        // EP-SEDE asociada al evento
        $epSedeId = $this->epSedeIdFromEvento($evento);
        if (!$epSedeId) {
            return $this->fail(
                'EVENT_WITHOUT_EP_SEDE',
                'Este evento no está asociado a una EP-SEDE.',
                422
            );
        }

        // Expediente ACTIVO del alumno en esa EP-SEDE
        $exp = ExpedienteAcademico::where('user_id', $user->id)
            ->where('ep_sede_id', $epSedeId)
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$exp) {
            return $this->fail(
                'DIFFERENT_EP_SEDE',
                'No perteneces a la EP-SEDE del evento o tu expediente no está ACTIVO.',
                422,
                ['evento_ep_sede_id' => $epSedeId]
            );
        }

        // El evento requiere inscripción previa
        $requiereInscripcion = (bool) $evento->requiere_inscripcion;
        if (!$requiereInscripcion) {
            return $this->fail(
                'REGISTRATION_NOT_REQUIRED',
                'Este evento no requiere inscripción previa.',
                422
            );
        }

        // Ventana de inscripción
        $now = Carbon::now();

        if ($evento->inscripcion_desde) {
            $desde = Carbon::parse($evento->inscripcion_desde);
            if ($now->lt($desde)) {
                return $this->fail(
                    'REGISTRATION_NOT_OPEN',
                    'La inscripción aún no está abierta para este evento.',
                    422,
                    ['inscripcion_desde' => $evento->inscripcion_desde]
                );
            }
        }

        if ($evento->inscripcion_hasta) {
            $hasta = Carbon::parse($evento->inscripcion_hasta);
            if ($now->gt($hasta)) {
                return $this->fail(
                    'REGISTRATION_CLOSED',
                    'La inscripción para este evento ya ha finalizado.',
                    422,
                    ['inscripcion_hasta' => $evento->inscripcion_hasta]
                );
            }
        }

        // Ya inscrito en el evento
        $yaInscrito = VmParticipacion::where([
            'participable_type' => VmEvento::class,
            'participable_id'   => $evento->id,
            'expediente_id'     => $exp->id,
        ])->exists();

        if ($yaInscrito) {
            return $this->fail('ALREADY_ENROLLED', 'Ya estás inscrito en este evento.', 422);
        }

        // Cupo máximo (si aplica)
        $cupoMax = $evento->cupo_maximo ? (int) $evento->cupo_maximo : null;

        if ($cupoMax) {
            $inscritos = VmParticipacion::where('participable_type', VmEvento::class)
                ->where('participable_id', $evento->id)
                ->count();

            if ($inscritos >= $cupoMax) {
                return $this->fail(
                    'EVENT_FULL',
                    'El evento ya alcanzó el cupo máximo de participantes.',
                    422,
                    ['cupo_maximo' => $cupoMax]
                );
            }
        }

        // Crear participación en el evento
        $part = VmParticipacion::create([
            'participable_type' => VmEvento::class,
            'participable_id'   => $evento->id,
            'expediente_id'     => $exp->id,
            'rol'               => 'ALUMNO',
            'estado'            => 'INSCRITO',
        ]);

        return response()->json([
            'ok'   => true,
            'code' => 'ENROLLED',
            'data' => [
                'participacion' => $part,
                'evento'        => [
                    'id'                   => (int) $evento->id,
                    'requiere_inscripcion' => $requiereInscripcion,
                    'cupo_maximo'          => $cupoMax,
                ],
            ],
        ], 201);
    }


    public function misEventos(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        // filtros opcionales
        $periodoId = $request->integer('periodo_id') ?: null;
        $estadoFiltro = strtoupper((string) $request->query('estado_participacion', 'ACTIVOS'));
        // ACTIVOS = INSCRITO + CONFIRMADO / FINALIZADOS = FINALIZADO / TODOS = sin filtro

        $estadosParticipacion = null;
        if ($estadoFiltro === 'ACTIVOS') {
            $estadosParticipacion = ['INSCRITO', 'CONFIRMADO'];
        } elseif ($estadoFiltro === 'FINALIZADOS') {
            $estadosParticipacion = ['FINALIZADO'];
        } // 'TODOS' ⇒ null

        // Query base: participaciones del usuario en eventos
        $q = DB::table('vm_participaciones as vp')
            ->join('expedientes_academicos as ea', 'ea.id', '=', 'vp.expediente_id')
            ->join('vm_eventos as ve', 've.id', '=', 'vp.participable_id')
            ->join('periodos_academicos as pa', 'pa.id', '=', 've.periodo_id')
            ->where('vp.participable_type', VmEvento::class)
            ->where('ea.user_id', $user->id)
            ->where('vp.rol', 'ALUMNO');

        if ($estadosParticipacion) {
            $q->whereIn('vp.estado', $estadosParticipacion);
        }

        if ($periodoId) {
            $q->where('ve.periodo_id', $periodoId);
        }

        $rows = $q->select([
                'vp.id as participacion_id',
                'vp.estado as participacion_estado',

                've.id as evento_id',
                've.codigo',
                've.titulo',
                've.subtitulo',
                've.modalidad',
                've.estado as evento_estado',
                've.periodo_id',
                've.requiere_inscripcion',
                've.cupo_maximo',

                // campos extra del evento
                've.descripcion_corta',
                've.descripcion_larga',
                've.lugar_detallado',
                've.url_imagen_portada',
                've.url_enlace_virtual',
                've.inscripcion_desde',
                've.inscripcion_hasta',

                'pa.anio as periodo_anio',
                'pa.ciclo as periodo_ciclo',
                'pa.estado as periodo_estado',
            ])
            ->orderByDesc('pa.anio')
            ->orderByDesc('pa.ciclo')
            ->orderBy('ve.id')
            ->get();

        // construir periodos únicos (solo donde el alumno tiene algo)
        $periodos = $rows
            ->groupBy('periodo_id')
            ->map(function ($grupo) {
                $first = $grupo->first();
                return [
                    'id'            => (int) $first->periodo_id,
                    'anio'          => (int) $first->periodo_anio,
                    'ciclo'         => (int) $first->periodo_ciclo,
                    'estado'        => $first->periodo_estado,
                    'total_eventos' => $grupo->count(),
                ];
            })
            ->values()
            ->sortByDesc('anio')
            ->sortByDesc('ciclo')
            ->values();

        // construir listado de eventos
        $eventos = $rows->map(function ($r) {
            return [
                'id'          => (int) $r->evento_id,
                'codigo'      => $r->codigo,
                'titulo'      => $r->titulo,
                'subtitulo'   => $r->subtitulo,
                'modalidad'   => $r->modalidad,
                'estado'      => $r->evento_estado,
                'periodo_id'  => (int) $r->periodo_id,
                'requiere_inscripcion' => (bool) $r->requiere_inscripcion,
                'cupo_maximo' => $r->cupo_maximo ? (int) $r->cupo_maximo : null,

                'descripcion_corta'  => $r->descripcion_corta,
                'descripcion_larga'  => $r->descripcion_larga,
                'lugar_detallado'    => $r->lugar_detallado,
                'url_imagen_portada' => $r->url_imagen_portada,
                'url_enlace_virtual' => $r->url_enlace_virtual,
                'inscripcion_desde'  => $r->inscripcion_desde,
                'inscripcion_hasta'  => $r->inscripcion_hasta,

                'periodo' => [
                    'id'     => (int) $r->periodo_id,
                    'anio'   => (int) $r->periodo_anio,
                    'ciclo'  => (int) $r->periodo_ciclo,
                    'estado' => $r->periodo_estado,
                ],

                'participacion' => [
                    'id'     => (int) $r->participacion_id,
                    'estado' => $r->participacion_estado,
                ],
            ];
        });

        return response()->json([
            'ok'   => true,
            'code' => 'MY_EVENTS_LIST',
            'data' => [
                'periodos' => $periodos,
                'eventos'  => $eventos,
            ],
        ], 200);
    }


    /**
     * GET /api/vm/eventos/{evento}/inscritos  (STAFF)
     */
    public function listarInscritos(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $epSedeId = $this->epSedeIdFromEvento($evento);
        if (!$epSedeId || !EpScopeService::userManagesEpSede($user->id, $epSedeId)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para esta EP_SEDE.'], 403);
        }

        $estadoFiltro = strtoupper((string) $request->query('estado', 'TODOS'));
        $roles = (array) $request->query('roles', []);

        $q = VmParticipacion::query()
            ->where('participable_type', VmEvento::class)
            ->where('participable_id', $evento->id)
            ->with(['expediente.user']);

        if (!empty($roles)) {
            $q->whereIn('rol', $roles);
        }

        if ($estadoFiltro === 'ACTIVOS') {
            $q->whereIn('estado', ['INSCRITO', 'CONFIRMADO']);
        } elseif ($estadoFiltro === 'FINALIZADOS') {
            $q->where('estado', 'FINALIZADO');
        }

        $participaciones = $q->orderBy('id')->get();

        $items = $participaciones->map(function ($p) {
            $u = optional(optional($p->expediente)->user);
            $fullName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null;
            $userId = $u->id ?? null;

            return [
                'participacion_id' => (int) $p->id,
                'rol'              => $p->rol,
                'estado'           => $p->estado,
                'expediente'       => [
                    'id'     => (int) $p->expediente_id,
                    'codigo' => optional($p->expediente)->codigo_estudiante,
                    'grupo'  => optional($p->expediente)->grupo,
                    // ✅ añadimos ciclo para alinearlo con ExpedienteRef del front
                    'ciclo'  => optional($p->expediente)->ciclo,
                    'usuario'=> [
                        'id'         => $userId ? (int) $userId : null,
                        'first_name' => $u->first_name,
                        'last_name'  => $u->last_name,
                        'full_name'  => $fullName,
                        'email'      => $u->email,
                        'celular'    => $u->celular,
                    ],
                ],
            ];
        })->values();

        $resumen = [
            'total'       => $items->count(),
            'activos'     => $items->whereIn('estado', ['INSCRITO', 'CONFIRMADO'])->count(),
            'finalizados' => $items->where('estado', 'FINALIZADO')->count(),
        ];

        return response()->json([
            'ok'   => true,
            'code' => 'EVENT_ENROLLED_LIST',
            'data' => [
                'evento'   => [
                    'id'                   => (int) $evento->id,
                    'estado'               => $evento->estado,
                    'requiere_inscripcion' => (bool) $evento->requiere_inscripcion,
                    'cupo_maximo'          => $evento->cupo_maximo,
                ],
                'resumen'  => $resumen,
                'inscritos'=> $items,
            ],
        ], 200);
    }

    /**
     * GET /api/vm/eventos/{evento}/candidatos  (STAFF)
     *
     * Conceptualmente igual que en proyectos pero sin chequear ciclos:
     * - Expedientes ACTIVOS en la misma EP_SEDE
     * - No inscritos aún en el evento
     */
    public function listarCandidatos(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $epSedeId = $this->epSedeIdFromEvento($evento);
        if (!$epSedeId || !EpScopeService::userManagesEpSede($user->id, $epSedeId)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para esta EP_SEDE.'], 403);
        }

        $soloElegibles = filter_var($request->query('solo_elegibles', 'true'), FILTER_VALIDATE_BOOLEAN);
        $limit = (int) $request->query('limit', 0);
        $queryText = trim((string) $request->query('q', ''));

        $expedientes = ExpedienteAcademico::query()
            ->where('ep_sede_id', $epSedeId)
            ->activos()
            ->with('user')
            ->when($queryText !== '', function ($q) use ($queryText) {
                $q->where(function ($qq) use ($queryText) {
                    $qq->where('codigo_estudiante', 'like', "%{$queryText}%")
                       ->orWhereHas('user', function ($u) use ($queryText) {
                           $u->where('first_name', 'like', "%{$queryText}%")
                             ->orWhere('last_name', 'like', "%{$queryText}%")
                             ->orWhere(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$queryText}%")
                             ->orWhere('email', 'like', "%{$queryText}%")
                             ->orWhere('celular', 'like', "%{$queryText}%");
                       });
                });
            })
            ->orderBy('id')
            ->cursor();

        $candidatos = [];
        $descartados = [];

        $eventoActivo = in_array($evento->estado, ['PLANIFICADO', 'EN_CURSO']);

        foreach ($expedientes as $exp) {

            $ya = VmParticipacion::where([
                'participable_type' => VmEvento::class,
                'participable_id'   => $evento->id,
                'expediente_id'     => $exp->id,
            ])->exists();

            if ($ya) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'razon'         => 'ALREADY_ENROLLED',
                    ];
                }
                continue;
            }

            if (!$eventoActivo) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'razon'         => 'EVENT_NOT_ACTIVE',
                        'meta'          => ['estado' => $evento->estado],
                    ];
                }
                continue;
            }

            $u = optional($exp->user);
            $fullName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null;

            $candidatos[] = [
                'expediente_id' => (int) $exp->id,
                'codigo'        => $exp->codigo_estudiante,
                // ✅ añadimos grupo y ciclo para que el front los pueda mostrar
                'grupo'         => $exp->grupo,
                'ciclo'         => $exp->ciclo,
                'usuario'       => [
                    'id'         => $u->id ? (int) $u->id : null,
                    'first_name' => $u->first_name,
                    'last_name'  => $u->last_name,
                    'full_name'  => $fullName,
                    'email'      => $u->email,
                    'celular'    => $u->celular,
                ],
                'motivo'        => 'ELEGIBLE_EVENTO',
            ];

            if ($limit > 0 && count($candidatos) >= $limit) {
                break;
            }
        }

        return response()->json([
            'ok'   => true,
            'code' => 'EVENT_CANDIDATES_LIST',
            'data' => [
                'evento'            => ['id' => (int) $evento->id],
                'candidatos_total'  => count($candidatos),
                'descartados_total' => $soloElegibles ? 0 : count($descartados),
                'candidatos'        => $candidatos,
                'no_elegibles'      => $soloElegibles ? [] : $descartados,
            ],
        ], 200);
    }

    // ───────────────────────── Helpers ─────────────────────────

    private function epSedeIdFromEvento(VmEvento $evento): ?int
    {
        if ($evento->targetable_type === 'ep_sede' && $evento->targetable_id) {
            return (int) $evento->targetable_id;
        }
        return null;
    }

    private function fail(string $code, string $message, int $status = 422, array $meta = []): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
            'meta'    => (object) $meta,
        ], $status);
    }
}
