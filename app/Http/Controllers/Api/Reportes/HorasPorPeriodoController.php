<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Auth\EpScopeService;
use App\Services\Reportes\HorasPorPeriodoService;
use App\Exports\HorasPorPeriodoExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Maatwebsite\Excel\Facades\Excel;

class HorasPorPeriodoController extends Controller
{
    /** Resuelve EP_SEDE desde path o desde el usuario; devuelve [int|null $id, \Illuminate\Http\JsonResponse|null $error] */
    private function resolveEpSede(Request $request, ?int $epSedeId = null): array
    {
        $userId  = (int) $request->user()->id;
        $managed = EpScopeService::epSedesIdsManagedBy($userId); // array de IDs

        if ($epSedeId !== null) {
            if (in_array($epSedeId, $managed, true) && EpScopeService::userManagesEpSede($userId, $epSedeId)) {
                return [$epSedeId, null];
            }
            return [null, response()->json([
                'message' => 'No autorizado para consultar este EP_SEDE.'
            ], Response::HTTP_FORBIDDEN)];
        }

        // Sin parámetro: intento inferir
        if (count($managed) === 1) {
            return [$managed[0], null];
        }

        if (count($managed) > 1) {
            return [null, response()->json([
                'message' => 'Selecciona un EP_SEDE.',
                'choices' => array_values($managed),
            ], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }

        return [null, response()->json([
            'message' => 'No tienes EP_SEDE asignadas o permisos.'
        ], Response::HTTP_FORBIDDEN)];
    }

    // =================== Endpoints con parámetro ===================

    public function index(Request $request, int $epSedeId, HorasPorPeriodoService $service)
    {
        $userId = (int) $request->user()->id;

        if (!EpScopeService::userManagesEpSede($userId, $epSedeId)) {
            return response()->json([
                'message' => 'No autorizado para consultar este EP_SEDE.'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'periodos'                => 'array',
            'periodos.*'              => 'regex:/^\d{4}\-(1|2)$/',
            'ultimos'                 => 'nullable|integer|min:1|max:12',
            'unidad'                  => 'in:h,min',
            'estado'                  => 'in:PENDIENTE,APROBADO,RECHAZADO,ANULADO',
            'solo_con_horas_periodos' => 'in:0,1',
            'orden'                   => 'in:apellidos,codigo,total',
            'dir'                     => 'in:asc,desc',
        ]);

        $unidad  = $data['unidad'] ?? 'h';
        $estado  = $data['estado'] ?? 'APROBADO';
        $soloSel = ($data['solo_con_horas_periodos'] ?? '1') === '1';
        $orden   = $data['orden'] ?? 'apellidos';
        $dir     = $data['dir'] ?? 'asc';

        $periodoCodigos = $data['periodos'] ?? null;
        $ultimos        = $periodoCodigos ? null : ($data['ultimos'] ?? 5);

        $payload = $service->build(
            epSedeId: $epSedeId,
            periodoCodigos: $periodoCodigos,
            ultimos: $ultimos,
            estadoRegistro: $estado,
            soloConHorasEnSeleccion: $soloSel,
            unidad: $unidad,
            orden: $orden,
            dir: $dir
        );

        return response()->json($payload);
    }

    public function export(Request $request, int $epSedeId, HorasPorPeriodoService $service)
    {
        $userId = (int) $request->user()->id;

        if (!EpScopeService::userManagesEpSede($userId, $epSedeId)) {
            return response()->json([
                'message' => 'No autorizado para exportar este EP_SEDE.'
            ], Response::HTTP_FORBIDDEN);
        }

        $request->merge(['ultimos' => $request->input('periodos') ? null : ($request->input('ultimos', 5))]);

        $payload = $service->build(
            epSedeId: $epSedeId,
            periodoCodigos: $request->input('periodos'),
            ultimos: $request->input('ultimos'),
            estadoRegistro: $request->input('estado', 'APROBADO'),
            soloConHorasEnSeleccion: (string)$request->input('solo_con_horas_periodos', '1') === '1',
            unidad: $request->input('unidad', 'h'),
            orden: $request->input('orden', 'apellidos'),
            dir: $request->input('dir', 'asc')
        );

        $export = new HorasPorPeriodoExport($payload['meta'], $payload['data']);

        $fileName = sprintf('reporte_horas_ep_%d_%s.xlsx', $epSedeId, now()->format('Ymd_His'));
        return Excel::download($export, $fileName);
    }

    // =================== Endpoints AUTO (sin parámetro) ===================

    public function indexAuto(Request $request, HorasPorPeriodoService $service)
    {
        [$epSedeId, $error] = $this->resolveEpSede($request, null);
        if ($error) return $error;
        // Reutiliza la misma validación/lógica
        return $this->index($request, $epSedeId, $service);
    }

    public function exportAuto(Request $request, HorasPorPeriodoService $service)
    {
        [$epSedeId, $error] = $this->resolveEpSede($request, null);
        if ($error) return $error;
        return $this->export($request, $epSedeId, $service);
    }
}
