<?php

namespace App\Http\Controllers\Api\Matricula;

use App\Exports\MatriculaPlantillaExport;
use App\Http\Controllers\Controller;
use App\Imports\MatriculaRegistroImport;
use App\Models\PeriodoAcademico;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Spatie\Permission\PermissionRegistrar; // limpiar caché Spatie

class MatriculaRegistroController extends Controller
{
    /**
     * Importa un Excel y realiza registro + expediente + matrícula,
     * o solo matrícula cuando el código de estudiante ya existe.
     *
     * Params (multipart/form-data):
     *  - file: excel (xls/xlsx/csv) (obligatorio)
     *  - periodo_id: int (obligatorio, debe ser actual: EN_CURSO o es_actual=1)
     *  - ep_sede_id: int (opcional; si no se envía, se deduce según permisos del usuario)
     */
    public function import(Request $request)
    {
        @set_time_limit(300);
        ini_set('max_execution_time', '300');

        $v = Validator::make($request->all(), [
            'file'       => ['required', 'file', 'mimes:xls,xlsx,csv', 'max:20480'],
            'ep_sede_id' => ['nullable', 'integer', 'exists:ep_sede,id'],
            'periodo_id' => ['required', 'integer', 'exists:periodos_academicos,id'],
        ], [], [
            'file'       => 'archivo Excel',
            'ep_sede_id' => 'EP-SEDE',
            'periodo_id' => 'período académico',
        ]);

        if ($v->fails()) {
            return response()->json(['ok' => false, 'errors' => $v->errors()], 422);
        }

        // Verificación de período actual
        $periodo = PeriodoAcademico::find($request->integer('periodo_id'));
        if (!$periodo || !($periodo->estado === 'EN_CURSO' || (bool) $periodo->es_actual)) {
            return response()->json([
                'ok' => false,
                'errors' => ['periodo_id' => ['El período debe ser actual (EN_CURSO o es_actual=1).']],
            ], 422);
        }

        // Resolver EP-SEDE del actor
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $epSedeId = $request->input('ep_sede_id');
        if ($epSedeId) {
            if (!EpScopeService::userManagesEpSede($actor->id, (int)$epSedeId)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No tienes permisos para gestionar la EP-SEDE indicada.',
                ], 403);
            }
            $epSedeId = (int)$epSedeId;
        } else {
            $managed = EpScopeService::epSedesIdsManagedBy($actor->id);
            if (count($managed) === 1) {
                $epSedeId = (int)$managed[0];
            } elseif (count($managed) > 1) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Administras más de una EP-SEDE. Especifica el parámetro ep_sede_id.',
                    'choices' => $managed,
                ], 422);
            } else {
                return response()->json([
                    'ok' => false,
                    'message' => 'No administras ninguna EP-SEDE activa.',
                ], 403);
            }
        }

        $import = new MatriculaRegistroImport(
            epSedeId: $epSedeId,
            periodoId: $periodo->id
        );

        try {
            $uploadedFile = $request->file('file');
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo no fue subido correctamente.'],
                ]);
            }

            // Limpia el caché de permisos/roles
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Congelar encabezados (nuestro import ya los normaliza)
            HeadingRowFormatter::default('none');

            Excel::import($import, $uploadedFile);

            return response()->json([
                'ok'      => true,
                'summary' => $import->summary(),
                'rows'    => $import->rows(),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);

        } catch (FileException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo leer el archivo enviado.',
                'error' => $e->getMessage(),
            ], 400);

        } catch (\ErrorException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'post_max_size') !== false || stripos($msg, 'upload_max_filesize') !== false) {
                return response()->json([
                    'ok' => false,
                    'message' => 'El archivo excede el tamaño permitido por el servidor (revisa upload_max_filesize y post_max_size).',
                    'error' => $msg,
                ], 413);
            }
            return response()->json([
                'ok' => false,
                'message' => 'Error al procesar el archivo.',
                'error' => $msg,
            ], 500);

        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (stripos($msg, 'ZipArchive') !== false) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Falta la extensión ZIP en PHP. Habilita "extension=zip" en php.ini y reinicia el servidor.',
                    'error' => $msg,
                ], 500);
            }

            if (stripos($msg, 'Heading row') !== false || stripos($msg, 'Invalid argument') !== false) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Encabezados inválidos o archivo mal formateado. Verifica las columnas esperadas por el importador.',
                    'error' => $msg,
                ], 422);
            }

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo procesar el archivo.',
                'error' => $msg,
            ], 500);
        }
    }

    public function plantilla(Request $request)
    {
        $filename = 'plantilla_matriculas.xlsx';
        return Excel::download(new MatriculaPlantillaExport, $filename);
    }
}
