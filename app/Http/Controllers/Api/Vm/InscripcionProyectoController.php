<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\VmParticipacion;
use App\Models\VmProceso;
use App\Models\VmProyecto;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InscripcionProyectoController extends Controller
{
    /**
     * POST /api/vm/proyectos/{proyecto}/inscribirse
     *
     * Respuestas (status, code):
     * - 201 ENROLLED
     * - 401 UNAUTHENTICATED
     * - 403 UNAUTHORIZED
     * - 422 PROJECT_NOT_ACTIVE | DIFFERENT_EP_SEDE | STUDENT_NOT_ACTIVE |
     *      NOT_ENROLLED_CURRENT_PERIOD | LEVEL_MISMATCH |
     *      PENDING_LINKED_PREV | ALREADY_ENROLLED
     */
    public function inscribirProyecto(Request $request, VmProyecto $proyecto): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        // Normalizar tipo: PROYECTO => VINCULADO (compatibilidad)
        $tipo = strtoupper((string) $proyecto->tipo);
        if ($tipo === 'PROYECTO') {
            $tipo = 'VINCULADO';
        }

        // 1) Proyecto vigente
        if (!in_array($proyecto->estado, ['PLANIFICADO', 'EN_CURSO'], true)) {
            return $this->fail('PROJECT_NOT_ACTIVE', 'El proyecto no admite inscripciones.', 422, [
                'estado' => $proyecto->estado,
            ]);
        }

        // 2) Expediente ACTIVO del alumno en la misma EP_SEDE
        $exp = ExpedienteAcademico::where('user_id', $user->id)
            ->where('ep_sede_id', $proyecto->ep_sede_id)
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$exp) {
            return $this->fail(
                'DIFFERENT_EP_SEDE',
                'No perteneces a la EP_SEDE del proyecto o tu expediente no está ACTIVO.',
                422,
                ['proyecto_ep_sede_id' => (int) $proyecto->ep_sede_id]
            );
        }

        // 3) Ya inscrito en este proyecto
        $yaInscrito = VmParticipacion::where([
            'participable_type' => VmProyecto::class,
            'participable_id'   => $proyecto->id,
            'expediente_id'     => $exp->id,
        ])->exists();

        if ($yaInscrito) {
            return $this->fail('ALREADY_ENROLLED', 'Ya estás inscrito en este proyecto.', 422);
        }

        // 4) Reglas por tipo
        if ($tipo === 'LIBRE') {
            // LIBRE: solo requiere ACTIVO + misma sede
            $part = VmParticipacion::create([
                'participable_type' => VmProyecto::class,
                'participable_id'   => $proyecto->id,
                'expediente_id'     => $exp->id,
                'rol'               => 'ALUMNO',
                'estado'            => 'INSCRITO',
            ]);

            return response()->json([
                'ok'   => true,
                'code' => 'ENROLLED',
                'data' => [
                    'participacion' => $part,
                    'proyecto'      => [
                        'id'    => (int) $proyecto->id,
                        'tipo'  => 'LIBRE',
                        'nivel' => $proyecto->nivel, // compat solo para front
                    ],
                ],
            ], 201);
        }

        // === VINCULADO ===

        // A) Matrícula vigente en período actual
        $periodoActual = PeriodoAcademico::query()
            ->where('es_actual', true)
            ->first();

        if (!$periodoActual) {
            return $this->fail('NO_CURRENT_PERIOD', 'No hay un período académico marcado como actual.', 422);
        }

        $matriculaActual = Matricula::where('expediente_id', $exp->id)
            ->where('periodo_id', $periodoActual->id)
            ->first();

        if (!$matriculaActual) {
            return $this->fail(
                'NOT_ENROLLED_CURRENT_PERIOD',
                'No estás matriculado en el período académico actual.',
                422,
                [
                    'periodo_id'     => (int) $periodoActual->id,
                    'periodo_codigo' => $periodoActual->codigo,
                ]
            );
        }

        // B) Coincidencia nivel (proyecto) = ciclo (alumno), usando multiciclo
        $cicloExp   = $this->toIntOrNull($exp->ciclo);
        $cicloMat   = $this->toIntOrNull($matriculaActual->ciclo);
        $cicloEval  = $cicloMat ?? $cicloExp; // prioridad: matrícula
        $nivelesPro = $this->nivelesProyecto($proyecto);

        if (empty($nivelesPro) || $cicloEval === null || !in_array((int) $cicloEval, $nivelesPro, true)) {
            return $this->fail(
                'LEVEL_MISMATCH',
                'Este proyecto es para estudiantes de los ciclos: ' . (empty($nivelesPro) ? 'N/D' : implode(', ', $nivelesPro)) . '.',
                422,
                [
                    'proyecto_niveles' => $nivelesPro,
                    'ciclo_expediente' => $cicloExp,
                    'ciclo_matricula'  => $cicloMat,
                    'ciclo_usado'      => $cicloEval,
                ]
            );
        }

        // C) Bloqueo si existe VINCULADO pendiente (horas < requeridas) en la misma sede
        if ($pend = $this->buscarPendienteVinculado($exp->id, $proyecto->ep_sede_id)) {
            $reqMin = $this->minutosRequeridosProyecto($pend['proyecto']);
            $acum   = $this->minutosValidadosProyecto($pend['proyecto']->id, $exp->id);
            $faltan = max(0, $reqMin - $acum);

            $nivelesPend = $this->nivelesProyecto($pend['proyecto']);
            $nivText     = empty($nivelesPend) ? 'N/D' : implode(', ', $nivelesPend);

            $cerrado = in_array($pend['proyecto']->estado, ['CERRADO', 'CANCELADO'], true);
            $msg = 'Tienes un proyecto VINCULADO pendiente (niveles ' . $nivText
                . ') del periodo ' . $pend['periodo'] . '; te faltan ' . ceil($faltan / 60)
                . ' h. ' . (
                    $cerrado
                        ? 'Ese proyecto está cerrado. No puedes inscribirte a VINCULADOS hasta regularizar. Puedes tomar LIBRES.'
                        : 'Continúa ese proyecto para completarlo.'
                );

            return $this->fail('PENDING_LINKED_PREV', $msg, 422, [
                'proyecto_id'     => (int) $pend['proyecto']->id,
                'niveles'         => $nivelesPend,
                'periodo'         => $pend['periodo'],
                'requerido_min'   => $reqMin,
                'acumulado_min'   => $acum,
                'faltan_min'      => $faltan,
                'cerrado'         => $cerrado,
            ]);
        }

        // D) Crear participación
        $part = DB::transaction(function () use ($proyecto, $exp) {
            return VmParticipacion::firstOrCreate(
                [
                    'participable_type' => VmProyecto::class,
                    'participable_id'   => $proyecto->id,
                    'expediente_id'     => $exp->id,
                ],
                [
                    'rol'    => 'ALUMNO',
                    'estado' => 'INSCRITO',
                ]
            );
        });

        // nivel_resumen solo para info de front; usamos el primer nivel si existe
        $nivelResumen = $this->nivelResumen($proyecto);

        return response()->json([
            'ok'   => true,
            'code' => 'ENROLLED',
            'data' => [
                'participacion' => $part,
                'proyecto'      => [
                    'id'    => (int) $proyecto->id,
                    'tipo'  => 'VINCULADO',
                    'nivel' => $nivelResumen,
                ],
            ],
        ], 201);
    }

    /**
     * POST /api/vm/proyectos/{proyecto}/inscribir-todos-candidatos  (STAFF)
     *
     * Inscribe MASIVAMENTE a los candidatos elegibles del proyecto.
     * Usa la misma lógica que listarCandidatos.
     */
    public function inscribirTodosCandidatos(Request $request, VmProyecto $proyecto): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para esta EP_SEDE.'], 403);
        }

        $tipo           = $this->normalizarTipo($proyecto);
        $soloElegibles  = filter_var($request->query('solo_elegibles', 'true'), FILTER_VALIDATE_BOOLEAN);
        $limit          = (int) $request->query('limit', 0);
        $queryText      = trim((string) $request->query('q', ''));
        $nivelesPro     = $this->nivelesProyecto($proyecto);

        $periodoActual  = PeriodoAcademico::query()->where('es_actual', true)->first();
        $proyectoActivo = in_array($proyecto->estado, ['PLANIFICADO', 'EN_CURSO'], true);

        $expedientes = ExpedienteAcademico::query()
            ->where('ep_sede_id', $proyecto->ep_sede_id)
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

        $creados     = 0;
        $yaInscritos = 0;
        $descartados = [];

        foreach ($expedientes as $exp) {

            // Ya inscrito en el proyecto
            $ya = VmParticipacion::where([
                'participable_type' => VmProyecto::class,
                'participable_id'   => $proyecto->id,
                'expediente_id'     => $exp->id,
            ])->exists();

            if ($ya) {
                $yaInscritos++;
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'ALREADY_ENROLLED',
                    ];
                }
                continue;
            }

            // Proyecto no activo → no elegible
            if (!$proyectoActivo) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'PROJECT_NOT_ACTIVE',
                        'meta'          => ['estado' => $proyecto->estado],
                    ];
                }
                continue;
            }

            $esElegible      = true;
            $razonNoElegible = null;
            $metaNoElegible  = [];

            if ($tipo === 'LIBRE') {
                // LIBRE: basta ACTIVO + misma sede
                $esElegible = true;
            } else {
                // === VINCULADO === (misma lógica que listarCandidatos)

                // 1) Período actual
                if (!$periodoActual) {
                    $esElegible      = false;
                    $razonNoElegible = 'NO_CURRENT_PERIOD';
                } else {

                    // 2) Matrícula en período actual
                    $matriculaActual = Matricula::where('expediente_id', $exp->id)
                        ->where('periodo_id', $periodoActual->id)
                        ->first();

                    if (!$matriculaActual) {
                        $esElegible      = false;
                        $razonNoElegible = 'NOT_ENROLLED_CURRENT_PERIOD';
                        $metaNoElegible  = [
                            'periodo_id'     => (int) $periodoActual->id,
                            'periodo_codigo' => $periodoActual->codigo,
                        ];
                    } else {
                        // 3) nivel = ciclo (usando multiciclo)
                        $cicloExp  = $this->toIntOrNull($exp->ciclo);
                        $cicloMat  = $this->toIntOrNull($matriculaActual->ciclo);
                        $cicloEval = $cicloMat ?? $cicloExp;

                        if (empty($nivelesPro) || $cicloEval === null || !in_array((int) $cicloEval, $nivelesPro, true)) {
                            $esElegible      = false;
                            $razonNoElegible = 'LEVEL_MISMATCH';
                            $metaNoElegible  = [
                                'proyecto_niveles' => $nivelesPro,
                                'ciclo_expediente' => $cicloExp,
                                'ciclo_matricula'  => $cicloMat,
                                'ciclo_usado'      => $cicloEval,
                            ];
                        } else {
                            // 4) No debe haber VINCULADO pendiente
                            if ($pend = $this->buscarPendienteVinculado($exp->id, $proyecto->ep_sede_id)) {
                                $esElegible      = false;
                                $razonNoElegible = 'PENDING_LINKED_PREV';
                                $reqMin = $this->minutosRequeridosProyecto($pend['proyecto']);
                                $acum   = $this->minutosValidadosProyecto($pend['proyecto']->id, $exp->id);

                                $metaNoElegible = [
                                    'proyecto_id'   => (int) $pend['proyecto']->id,
                                    'niveles'       => $this->nivelesProyecto($pend['proyecto']),
                                    'periodo'       => $pend['periodo'],
                                    'requerido_min' => $reqMin,
                                    'acumulado_min' => $acum,
                                    'faltan_min'    => max(0, $reqMin - $acum),
                                    'cerrado'       => in_array($pend['proyecto']->estado, ['CERRADO','CANCELADO'], true),
                                ];
                            }
                        }
                    }
                }
            }

            if (!$esElegible) {
                if (!$soloElegibles) {
                    $item = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => $razonNoElegible,
                    ];
                    if (!empty($metaNoElegible)) {
                        $item['meta'] = $metaNoElegible;
                    }
                    $descartados[] = $item;
                }
                continue;
            }

            // ELEGIBLE → crear participación
            VmParticipacion::firstOrCreate(
                [
                    'participable_type' => VmProyecto::class,
                    'participable_id'   => $proyecto->id,
                    'expediente_id'     => $exp->id,
                ],
                [
                    'rol'    => 'ALUMNO',
                    'estado' => 'INSCRITO',
                ]
            );

            $creados++;

            if ($limit > 0 && $creados >= $limit) {
                break;
            }
        }

        $nivelResumen = $this->nivelResumen($proyecto);

        return response()->json([
            'ok'   => true,
            'code' => 'BULK_ENROLLED',
            'data' => [
                'proyecto'          => [
                    'id'    => (int) $proyecto->id,
                    'tipo'  => $tipo,
                    'nivel' => $nivelResumen,
                ],
                'creados'           => $creados,
                'ya_inscritos'      => $yaInscritos,
                'descartados_total' => $soloElegibles ? 0 : count($descartados),
                'descartados'       => $soloElegibles ? [] : $descartados,
            ],
        ], 200);
    }

    /**
     * GET /api/vm/proyectos/{proyecto}/inscritos  (STAFF)
     */
    public function listarInscritos(Request $request, VmProyecto $proyecto): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para esta EP_SEDE.'], 403);
        }

        $tipo         = $this->normalizarTipo($proyecto);
        $estadoFiltro = strtoupper((string) $request->query('estado', 'TODOS'));
        $roles        = (array) $request->query('roles', []); // roles[]=ALUMNO

        $q = VmParticipacion::query()
            ->where('participable_type', VmProyecto::class)
            ->where('participable_id', $proyecto->id)
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

        // Sumatoria de minutos en bloque
        $expIds   = $participaciones->pluck('expediente_id')->all();
        $minByExp = $this->minutosValidadosProyectoBulk($proyecto->id, $expIds);
        $reqMin   = $this->minutosRequeridosProyecto($proyecto);

        $items = $participaciones->map(function ($p) use ($reqMin, $minByExp) {
            $acum = (int) ($minByExp[$p->expediente_id] ?? 0);

            $u = optional(optional($p->expediente)->user);
            $fullName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null;
            $userId   = $u->id ?? null;

            return [
                'participacion_id' => (int) $p->id,
                'rol'              => $p->rol,
                'estado'           => $p->estado,
                'expediente'       => [
                    'id'     => (int) $p->expediente_id,
                    'codigo' => optional($p->expediente)->codigo_estudiante,
                    'grupo'  => optional($p->expediente)->grupo,
                    'usuario'=> [
                        'id'         => $userId ? (int) $userId : null,
                        'first_name' => $u->first_name,
                        'last_name'  => $u->last_name,
                        'full_name'  => $fullName,
                        'email'      => $u->email,
                        'celular'    => $u->celular,
                    ],
                ],
                'requerido_min' => $reqMin,
                'acumulado_min' => $acum,
                'faltan_min'    => max(0, $reqMin - $acum),
                'porcentaje'    => $reqMin ? (int) round(($acum / $reqMin) * 100) : null,
                'finalizado'    => strtoupper($p->estado) === 'FINALIZADO' || $acum >= $reqMin,
            ];
        })->values();

        $resumen = [
            'total'       => $items->count(),
            'activos'     => $items->whereIn('estado', ['INSCRITO', 'CONFIRMADO'])->count(),
            'finalizados' => $items->filter(fn ($i) => $i['finalizado'])->count(),
        ];

        $nivelResumen = $this->nivelResumen($proyecto);

        return response()->json([
            'ok'   => true,
            'code' => 'ENROLLED_LIST',
            'data' => [
                'proyecto' => [
                    'id'    => (int) $proyecto->id,
                    'tipo'  => $tipo,
                    'nivel' => $nivelResumen,
                ],
                'resumen'   => $resumen,
                'inscritos' => $items,
            ],
        ], 200);
    }

    /**
     * GET /api/vm/proyectos/{proyecto}/candidatos  (STAFF)
     */
    public function listarCandidatos(Request $request, VmProyecto $proyecto): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para esta EP_SEDE.'], 403);
        }

        $tipo          = $this->normalizarTipo($proyecto);
        $soloElegibles = filter_var($request->query('solo_elegibles', 'true'), FILTER_VALIDATE_BOOLEAN);
        $limit         = (int) $request->query('limit', 0);
        $queryText     = trim((string) $request->query('q', ''));

        $periodoActual  = PeriodoAcademico::query()->where('es_actual', true)->first();
        $proyectoActivo = in_array($proyecto->estado, ['PLANIFICADO', 'EN_CURSO'], true);
        $nivelesPro     = $this->nivelesProyecto($proyecto);

        $expedientes = ExpedienteAcademico::query()
            ->where('ep_sede_id', $proyecto->ep_sede_id)
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

        $candidatos  = [];
        $descartados = [];

        foreach ($expedientes as $exp) {

            // Ya inscrito en este mismo proyecto
            $ya = VmParticipacion::where([
                'participable_type' => VmProyecto::class,
                'participable_id'   => $proyecto->id,
                'expediente_id'     => $exp->id,
            ])->exists();

            if ($ya) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'ALREADY_ENROLLED',
                    ];
                }
                continue;
            }

            if (!$proyectoActivo) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'PROJECT_NOT_ACTIVE',
                        'meta'          => ['estado' => $proyecto->estado],
                    ];
                }
                continue;
            }

            if ($tipo === 'LIBRE') {
                // Para LIBRE: basta ACTIVO + misma sede
                $u = optional($exp->user);
                $fullName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null;

                $candidatos[] = [
                    'expediente_id' => (int) $exp->id,
                    'codigo'        => $exp->codigo_estudiante,
                    'ciclo'         => $this->toIntOrNull($exp->ciclo),
                    'grupo'         => $exp->grupo,
                    'usuario'       => [
                        'id'         => $u->id ? (int) $u->id : null,
                        'first_name' => $u->first_name,
                        'last_name'  => $u->last_name,
                        'full_name'  => $fullName,
                        'email'      => $u->email,
                        'celular'    => $u->celular,
                    ],
                    'motivo'        => 'ELEGIBLE_LIBRE',
                ];
            } else {
                // === VINCULADO ===

                // 1) Matrícula en período actual
                if (!$periodoActual) {
                    if (!$soloElegibles) {
                        $descartados[] = [
                            'expediente_id' => (int) $exp->id,
                            'codigo'        => $exp->codigo_estudiante,
                            'ciclo'         => $this->toIntOrNull($exp->ciclo),
                            'grupo'         => $exp->grupo,
                            'razon'         => 'NO_CURRENT_PERIOD',
                        ];
                    }
                    continue;
                }

                $matriculaActual = Matricula::where('expediente_id', $exp->id)
                    ->where('periodo_id', $periodoActual->id)
                    ->first();

                if (!$matriculaActual) {
                    if (!$soloElegibles) {
                        $descartados[] = [
                            'expediente_id' => (int) $exp->id,
                            'codigo'        => $exp->codigo_estudiante,
                            'ciclo'         => $this->toIntOrNull($exp->ciclo),
                            'grupo'         => $exp->grupo,
                            'razon'         => 'NOT_ENROLLED_CURRENT_PERIOD',
                            'meta'          => [
                                'periodo_id'     => (int) $periodoActual->id,
                                'periodo_codigo' => $periodoActual->codigo,
                            ],
                        ];
                    }
                    continue;
                }

                // 2) nivel = ciclo, usando multiciclo
                $cicloExp  = $this->toIntOrNull($exp->ciclo);
                $cicloMat  = $this->toIntOrNull($matriculaActual->ciclo);
                $cicloEval = $cicloMat ?? $cicloExp;

                if (empty($nivelesPro) || $cicloEval === null || !in_array((int) $cicloEval, $nivelesPro, true)) {
                    if (!$soloElegibles) {
                        $descartados[] = [
                            'expediente_id' => (int) $exp->id,
                            'codigo'        => $exp->codigo_estudiante,
                            'ciclo'         => $this->toIntOrNull($exp->ciclo),
                            'grupo'         => $exp->grupo,
                            'razon'         => 'LEVEL_MISMATCH',
                            'meta'          => [
                                'proyecto_niveles' => $nivelesPro,
                                'ciclo_expediente' => $cicloExp,
                                'ciclo_matricula'  => $cicloMat,
                                'ciclo_usado'      => $cicloEval,
                            ],
                        ];
                    }
                    continue;
                }

                // 3) No debe haber VINCULADO pendiente
                if ($pend = $this->buscarPendienteVinculado($exp->id, $proyecto->ep_sede_id)) {
                    if (!$soloElegibles) {
                        $reqMin = $this->minutosRequeridosProyecto($pend['proyecto']);
                        $acum   = $this->minutosValidadosProyecto($pend['proyecto']->id, $exp->id);
                        $descartados[] = [
                            'expediente_id' => (int) $exp->id,
                            'codigo'        => $exp->codigo_estudiante,
                            'ciclo'         => $this->toIntOrNull($exp->ciclo),
                            'grupo'         => $exp->grupo,
                            'razon'         => 'PENDING_LINKED_PREV',
                            'meta'          => [
                                'proyecto_id'   => (int) $pend['proyecto']->id,
                                'niveles'       => $this->nivelesProyecto($pend['proyecto']),
                                'periodo'       => $pend['periodo'],
                                'requerido_min' => $reqMin,
                                'acumulado_min' => $acum,
                                'faltan_min'    => max(0, $reqMin - $acum),
                                'cerrado'       => in_array($pend['proyecto']->estado, ['CERRADO','CANCELADO'], true),
                            ],
                        ];
                    }
                    continue;
                }

                $u = optional($exp->user);
                $fullName = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null;

                $candidatos[] = [
                    'expediente_id' => (int) $exp->id,
                    'codigo'        => $exp->codigo_estudiante,
                    'ciclo'         => $this->toIntOrNull($exp->ciclo),
                    'grupo'         => $exp->grupo,
                    'usuario'       => [
                        'id'         => $u->id ? (int) $u->id : null,
                        'first_name' => $u->first_name,
                        'last_name'  => $u->last_name,
                        'full_name'  => $fullName,
                        'email'      => $u->email,
                        'celular'    => $u->celular,
                    ],
                    'motivo'        => 'ELEGIBLE_VINCULADO',
                ];
            }

            if ($limit > 0 && count($candidatos) >= $limit) {
                break;
            }
        }

        $nivelResumen = $this->nivelResumen($proyecto);

        return response()->json([
            'ok'   => true,
            'code' => 'CANDIDATES_LIST',
            'data' => [
                'proyecto'          => [
                    'id'    => (int) $proyecto->id,
                    'tipo'  => $tipo,
                    'nivel' => $nivelResumen,
                ],
                'candidatos_total'  => count($candidatos),
                'descartados_total' => $soloElegibles ? 0 : count($descartados),
                'candidatos'        => $candidatos,
                'no_elegibles'      => $soloElegibles ? [] : $descartados,
            ],
        ], 200);
    }

    /**
     * POST /api/vm/proyectos/{proyecto}/inscribir-candidatos-seleccionados (STAFF)
     *
     * Body JSON:
     * {
     *   "expedientes": [1,2,3],
     *   "solo_elegibles": true|false // opcional, por defecto true
     * }
     */
    public function inscribirCandidatosSeleccionados(Request $request, VmProyecto $proyecto): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para esta EP_SEDE.'], 403);
        }

        $data = $request->validate([
            'expedientes'   => ['required', 'array', 'min:1'],
            'expedientes.*' => ['integer', 'exists:expedientes_academicos,id'],
            'solo_elegibles'=> ['sometimes', 'boolean'],
        ]);

        $tipo           = $this->normalizarTipo($proyecto);
        $nivelesPro     = $this->nivelesProyecto($proyecto);
        $periodoActual  = PeriodoAcademico::query()->where('es_actual', true)->first();
        $proyectoActivo = in_array($proyecto->estado, ['PLANIFICADO', 'EN_CURSO'], true);
        $soloElegibles  = $data['solo_elegibles'] ?? true;

        $creados     = 0;
        $yaInscritos = 0;
        $descartados = [];

        $expedientes = ExpedienteAcademico::query()
            ->whereIn('id', $data['expedientes'])
            ->where('ep_sede_id', $proyecto->ep_sede_id)
            ->activos()
            ->with('user')
            ->get();

        foreach ($expedientes as $exp) {
            // 1) Ya inscrito en este proyecto
            $ya = VmParticipacion::where([
                'participable_type' => VmProyecto::class,
                'participable_id'   => $proyecto->id,
                'expediente_id'     => $exp->id,
            ])->exists();

            if ($ya) {
                $yaInscritos++;
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'ALREADY_ENROLLED',
                    ];
                }
                continue;
            }

            if (!$proyectoActivo) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'PROJECT_NOT_ACTIVE',
                        'meta'          => ['estado' => $proyecto->estado],
                    ];
                }
                continue;
            }

            // LIBRE → basta ACTIVO + misma sede
            if ($tipo === 'LIBRE') {
                VmParticipacion::firstOrCreate(
                    [
                        'participable_type' => VmProyecto::class,
                        'participable_id'   => $proyecto->id,
                        'expediente_id'     => $exp->id,
                    ],
                    [
                        'rol'    => 'ALUMNO',
                        'estado' => 'INSCRITO',
                    ]
                );
                $creados++;
                continue;
            }

            // === VINCULADO ===
            if (!$periodoActual) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'NO_CURRENT_PERIOD',
                    ];
                }
                continue;
            }

            $matriculaActual = Matricula::where('expediente_id', $exp->id)
                ->where('periodo_id', $periodoActual->id)
                ->first();

            if (!$matriculaActual) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'NOT_ENROLLED_CURRENT_PERIOD',
                        'meta'          => [
                            'periodo_id'     => (int) $periodoActual->id,
                            'periodo_codigo' => $periodoActual->codigo,
                        ],
                    ];
                }
                continue;
            }

            // nivel = ciclo (multiciclo)
            $cicloExp  = $this->toIntOrNull($exp->ciclo);
            $cicloMat  = $this->toIntOrNull($matriculaActual->ciclo);
            $cicloEval = $cicloMat ?? $cicloExp;

            if (empty($nivelesPro) || $cicloEval === null || !in_array((int) $cicloEval, $nivelesPro, true)) {
                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'LEVEL_MISMATCH',
                        'meta'          => [
                            'proyecto_niveles' => $nivelesPro,
                            'ciclo_expediente' => $cicloExp,
                            'ciclo_matricula'  => $cicloMat,
                            'ciclo_usado'      => $cicloEval,
                        ],
                    ];
                }
                continue;
            }

            // No debe haber VINCULADO pendiente
            if ($pend = $this->buscarPendienteVinculado($exp->id, $proyecto->ep_sede_id)) {
                $reqMin = $this->minutosRequeridosProyecto($pend['proyecto']);
                $acum   = $this->minutosValidadosProyecto($pend['proyecto']->id, $exp->id);

                if (!$soloElegibles) {
                    $descartados[] = [
                        'expediente_id' => (int) $exp->id,
                        'codigo'        => $exp->codigo_estudiante,
                        'ciclo'         => $this->toIntOrNull($exp->ciclo),
                        'grupo'         => $exp->grupo,
                        'razon'         => 'PENDING_LINKED_PREV',
                        'meta'          => [
                            'proyecto_id'   => (int) $pend['proyecto']->id,
                            'niveles'       => $this->nivelesProyecto($pend['proyecto']),
                            'periodo'       => $pend['periodo'],
                            'requerido_min' => $reqMin,
                            'acumulado_min' => $acum,
                            'faltan_min'    => max(0, $reqMin - $acum),
                            'cerrado'       => in_array($pend['proyecto']->estado, ['CERRADO','CANCELADO'], true),
                        ],
                    ];
                }
                continue;
            }

            // Elegible → crear participación
            VmParticipacion::firstOrCreate(
                [
                    'participable_type' => VmProyecto::class,
                    'participable_id'   => $proyecto->id,
                    'expediente_id'     => $exp->id,
                ],
                [
                    'rol'    => 'ALUMNO',
                    'estado' => 'INSCRITO',
                ]
            );

            $creados++;
        }

        $nivelResumen = $this->nivelResumen($proyecto);

        return response()->json([
            'ok'   => true,
            'code' => 'PARTIAL_BULK_ENROLLED',
            'data' => [
                'proyecto'          => [
                    'id'    => (int) $proyecto->id,
                    'tipo'  => $tipo,
                    'nivel' => $nivelResumen,
                ],
                'creados'           => $creados,
                'ya_inscritos'      => $yaInscritos,
                'descartados_total' => count($descartados),
                'descartados'       => $descartados,
            ],
        ], 200);
    }

    // ───────────────────────── Helpers ─────────────────────────

    private function normalizarTipo(VmProyecto $proyecto): string
    {
        $tipo = strtoupper((string) $proyecto->tipo);
        return $tipo === 'PROYECTO' ? 'VINCULADO' : $tipo;
    }

    private function toIntOrNull($val): ?int
    {
        if ($val === null) return null;
        if (is_numeric($val)) return (int) $val;
        $digits = preg_replace('/\D+/', '', (string) $val);
        return $digits !== '' ? (int) $digits : null;
    }

    /**
     * Niveles (ciclos) asociados al proyecto vía vm_proyecto_ciclos.
     * Si no hay filas, intenta usar el campo nivel legacy del proyecto.
     */
    private function nivelesProyecto(VmProyecto $proyecto): array
    {
        $ciclos = $proyecto->relationLoaded('ciclos')
            ? $proyecto->ciclos
            : $proyecto->ciclos()->get();

        $niveles = collect($ciclos)
            ->pluck('nivel')
            ->filter(fn ($n) => $n !== null)
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->values()
            ->all();

        if (empty($niveles) && $proyecto->nivel !== null) {
            $niveles = [(int) $proyecto->nivel];
        }

        sort($niveles);
        return $niveles;
    }

    /**
     * Nivel "resumen" para enviar al front en meta del proyecto.
     */
    private function nivelResumen(VmProyecto $proyecto): ?int
    {
        $niveles = $this->nivelesProyecto($proyecto);
        if (!empty($niveles)) {
            return (int) $niveles[0];
        }

        return $proyecto->nivel !== null ? (int) $proyecto->nivel : null;
    }

    /**
     * Suma de minutos validados por proyecto para muchos expedientes (bulk).
     * SOLO cuenta sesiones cuyo sessionable es VmProceso y cuyo proceso tiene proyecto_id = $proyectoId.
     */
    protected function minutosValidadosProyectoBulk(int $proyectoId, array $expedienteIds): array
    {
        if (empty($expedienteIds)) {
            return [];
        }

        return DB::table('vm_asistencias as a')
            ->join('vm_sesiones as s', 'a.sesion_id', '=', 's.id')
            ->join('vm_procesos as p', 's.sessionable_id', '=', 'p.id')
            ->where('a.estado', 'VALIDADO')
            ->whereIn('a.expediente_id', $expedienteIds)
            ->where('p.proyecto_id', $proyectoId)
            // por si en la BD se guarda el FQCN o el alias del morph
            ->whereIn('s.sessionable_type', [VmProceso::class, 'vm_proceso'])
            ->select('a.expediente_id', DB::raw('COALESCE(SUM(a.minutos_validados),0) as total_min'))
            ->groupBy('a.expediente_id')
            ->pluck('total_min', 'expediente_id')
            ->toArray();
    }

    /**
     * Busca si existe algún proyecto VINCULADO pendiente (horas < requeridas)
     * para un expediente dentro de la misma EP_SEDE.
     */
    protected function buscarPendienteVinculado(int $expedienteId, int $epSedeId): ?array
    {
        $parts = VmParticipacion::query()
            ->where('participable_type', VmProyecto::class)
            ->where('expediente_id', $expedienteId)
            ->whereHas('participable', function ($q) use ($epSedeId) {
                $q->where('ep_sede_id', $epSedeId)
                  ->whereIn('estado', ['PLANIFICADO','EN_CURSO','CERRADO','CANCELADO'])
                  ->where(function ($qq) {
                      $qq->where('tipo', 'VINCULADO')
                         ->orWhere('tipo', 'PROYECTO'); // compat
                  });
            })
            ->get();

        foreach ($parts as $p) {
            /** @var VmProyecto $proj */
            $proj = $p->participable;
            if (strtoupper($p->estado) === 'FINALIZADO') continue;

            $req = $this->minutosRequeridosProyecto($proj);
            $acc = $this->minutosValidadosProyecto($proj->id, $expedienteId);

            if ($acc < $req) {
                return [
                    'proyecto' => $proj,
                    'periodo'  => optional($proj->periodo)->codigo ?? $proj->periodo_id,
                ];
            }
        }

        return null;
    }

    /**
     * Conservado por compatibilidad (no se usa directamente en las nuevas reglas).
     * Ojo: para lógica nueva deberías migrar a vm_proyecto_ciclos.
     */
    protected function existeNivelFinalizado(int $expedienteId, int $epSedeId, int $nivel): bool
    {
        $parts = VmParticipacion::query()
            ->where('participable_type', VmProyecto::class)
            ->where('expediente_id', $expedienteId)
            ->whereHas('participable', function ($q) use ($epSedeId) {
                $q->where('ep_sede_id', $epSedeId)
                  ->where(function ($qq) {
                      $qq->where('tipo', 'VINCULADO')
                         ->orWhere('tipo', 'PROYECTO');
                  });
            })
            ->get();

        foreach ($parts as $p) {
            /** @var VmProyecto $proj */
            $proj = $p->participable;

            if (strtoupper($p->estado) === 'FINALIZADO') {
                // podrías refinar por nivel si lo necesitas en el futuro
                return true;
            }

            $req = $this->minutosRequeridosProyecto($proj);
            $acc = $this->minutosValidadosProyecto($proj->id, $expedienteId);
            if ($acc >= $req) return true;
        }

        return false;
    }

    protected function minutosRequeridosProyecto(VmProyecto $proyecto): int
    {
        $h = $proyecto->horas_minimas_participante ?: $proyecto->horas_planificadas;
        return ((int) $h) * 60;
    }

    /**
     * Minutos validados para UN expediente y UN proyecto.
     * Igual criterio que el bulk: sólo procesos del proyecto.
     */
    protected function minutosValidadosProyecto(int $proyectoId, int $expedienteId): int
    {
        return (int) DB::table('vm_asistencias as a')
            ->join('vm_sesiones as s', 'a.sesion_id', '=', 's.id')
            ->join('vm_procesos as p', 's.sessionable_id', '=', 'p.id')
            ->where('a.estado', 'VALIDADO')
            ->where('a.expediente_id', $expedienteId)
            ->where('p.proyecto_id', $proyectoId)
            ->whereIn('s.sessionable_type', [VmProceso::class, 'vm_proceso'])
            ->sum('a.minutos_validados');
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
