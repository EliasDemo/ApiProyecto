<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Universidad;
use App\Models\Sede;
use App\Models\Facultad;
use App\Models\EscuelaProfesional;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;

class AcademicoBaseSeeder extends Seeder
{
    public function run(): void
    {
        // ===== UNIVERSIDAD ÚNICA =====
        $uni = Universidad::where('codigo', 'UPeU')->firstOrFail();

        // ===== SEDES / CAMPUS REALES =====
        // Campus Lima (principal), Juliaca, Tarapoto
        $sedeLima = Sede::firstOrCreate(
            ['universidad_id' => $uni->id, 'nombre' => 'Campus Lima'],
            ['es_principal' => true, 'esta_suspendida' => false]
        );

        $sedeJuliaca = Sede::firstOrCreate(
            ['universidad_id' => $uni->id, 'nombre' => 'Campus Juliaca'],
            ['es_principal' => false, 'esta_suspendida' => false]
        );

        $sedeTarapoto = Sede::firstOrCreate(
            ['universidad_id' => $uni->id, 'nombre' => 'Campus Tarapoto'],
            ['es_principal' => false, 'esta_suspendida' => false]
        );

        $sedes = [
            'LIMA'     => $sedeLima,
            'JULIACA'  => $sedeJuliaca,
            'TARAPOTO' => $sedeTarapoto,
        ];

        // ===== FACULTADES REALES =====
        // Códigos sugeridos según uso común: FCE, FHE, FIA, FCS, FTEO
        $facultades = [];

        $facultades['FCE'] = Facultad::firstOrCreate(
            ['universidad_id' => $uni->id, 'codigo' => 'FCE'],
            ['nombre' => 'Facultad de Ciencias Empresariales']
        );

        $facultades['FHE'] = Facultad::firstOrCreate(
            ['universidad_id' => $uni->id, 'codigo' => 'FHE'],
            ['nombre' => 'Facultad de Ciencias Humanas y Educación']
        );

        $facultades['FIA'] = Facultad::firstOrCreate(
            ['universidad_id' => $uni->id, 'codigo' => 'FIA'],
            ['nombre' => 'Facultad de Ingeniería y Arquitectura']
        );

        $facultades['FCS'] = Facultad::firstOrCreate(
            ['universidad_id' => $uni->id, 'codigo' => 'FCS'],
            ['nombre' => 'Facultad de Ciencias de la Salud']
        );

        $facultades['FTEO'] = Facultad::firstOrCreate(
            ['universidad_id' => $uni->id, 'codigo' => 'FTEO'],
            ['nombre' => 'Facultad de Teología']
        );

        // ===== ESCUELAS / CARRERAS REALES (subset razonable) =====
        // Estructura: por facultad => [ [codigo, nombre, sedes[]], ... ]
        $escuelasPorFacultad = [

            // --- Ciencias Empresariales ---
            'FCE' => [
                [
                    'codigo' => 'ADM',
                    'nombre' => 'Administración',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'CGT',
                    'nombre' => 'Contabilidad, Gestión Tributaria y Aduanera',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'MKT',
                    'nombre' => 'Marketing y Negocios Internacionales',
                    'sedes'  => ['LIMA', 'TARAPOTO'],
                ],
            ],

            // --- Ciencias Humanas y Educación ---
            'FHE' => [
                [
                    'codigo' => 'CCOM',
                    'nombre' => 'Ciencias de la Comunicación',
                    'sedes'  => ['LIMA'],
                ],
                [
                    'codigo' => 'CAUD',
                    'nombre' => 'Comunicación Audiovisual y Medios Interactivos',
                    'sedes'  => ['LIMA'],
                ],
                [
                    'codigo' => 'EDIN',
                    'nombre' => 'Educación Inicial y Puericultura',
                    'sedes'  => ['LIMA', 'JULIACA'],
                ],
                [
                    'codigo' => 'EDPR',
                    'nombre' => 'Educación Primaria y Pedagogía Terapéutica',
                    'sedes'  => ['LIMA', 'JULIACA'],
                ],
                [
                    'codigo' => 'EDLI',
                    'nombre' => 'Educación, Especialidad Inglés y Español',
                    'sedes'  => ['LIMA', 'JULIACA'],
                ],
            ],

            // --- Ingeniería y Arquitectura ---
            'FIA' => [
                [
                    'codigo' => 'ARQ',
                    'nombre' => 'Arquitectura y Urbanismo',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'AMB',
                    'nombre' => 'Ingeniería Ambiental',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'ALIM',
                    'nombre' => 'Ingeniería de Industrias Alimentarias',
                    'sedes'  => ['LIMA', 'JULIACA'],
                ],
                [
                    'codigo' => 'CIV',
                    'nombre' => 'Ingeniería Civil',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'SIS',
                    'nombre' => 'Ingeniería de Sistemas',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
            ],

            // --- Ciencias de la Salud ---
            'FCS' => [
                [
                    'codigo' => 'ENF',
                    'nombre' => 'Enfermería',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'NUT',
                    'nombre' => 'Nutrición Humana',
                    'sedes'  => ['LIMA', 'JULIACA'],
                ],
                [
                    'codigo' => 'PSI',
                    'nombre' => 'Psicología',
                    'sedes'  => ['LIMA', 'JULIACA', 'TARAPOTO'],
                ],
                [
                    'codigo' => 'MED',
                    'nombre' => 'Medicina Humana',
                    'sedes'  => ['LIMA'],
                ],
            ],

            // --- Teología ---
            'FTEO' => [
                [
                    'codigo' => 'TEO',
                    'nombre' => 'Teología',
                    'sedes'  => ['LIMA'],
                ],
            ],
        ];

        // ===== CREAR ESCUELAS Y EP_SEDE =====
        foreach ($escuelasPorFacultad as $codFac => $escuelas) {
            $facultad = $facultades[$codFac];

            foreach ($escuelas as $escData) {
                $escuela = EscuelaProfesional::firstOrCreate(
                    [
                        'facultad_id' => $facultad->id,
                        'codigo'      => $escData['codigo'],
                    ],
                    [
                        'nombre' => $escData['nombre'],
                    ]
                );

                // Vincular cada escuela con las sedes donde se dicta (EpSede)
                foreach ($escData['sedes'] as $sedeKey) {
                    if (!isset($sedes[$sedeKey])) {
                        continue;
                    }

                    EpSede::firstOrCreate([
                        'escuela_profesional_id' => $escuela->id,
                        'sede_id'                => $sedes[$sedeKey]->id,
                    ]);
                }
            }
        }

        // ===== PERÍODOS ACADÉMICOS (igual que tu versión) =====
        $periodos = [
            // 2 anteriores
            [
                'codigo' => '2024-2',
                'anio' => 2024, 'ciclo' => 2,
                'estado' => 'CERRADO', 'es_actual' => false,
                'fecha_inicio' => '2024-08-01', 'fecha_fin' => '2024-12-15',
            ],
            [
                'codigo' => '2025-1',
                'anio' => 2025, 'ciclo' => 1,
                'estado' => 'CERRADO', 'es_actual' => false,
                'fecha_inicio' => '2025-03-01', 'fecha_fin' => '2025-07-15',
            ],

            // actual
            [
                'codigo' => '2025-2',
                'anio' => 2025, 'ciclo' => 2,
                'estado' => 'EN_CURSO', 'es_actual' => true,
                'fecha_inicio' => '2025-08-01', 'fecha_fin' => '2025-12-15',
            ],

            // 2 posteriores
            [
                'codigo' => '2026-1',
                'anio' => 2026, 'ciclo' => 1,
                'estado' => 'PLANIFICADO', 'es_actual' => false,
                'fecha_inicio' => '2026-03-01', 'fecha_fin' => '2026-07-15',
            ],
            [
                'codigo' => '2026-2',
                'anio' => 2026, 'ciclo' => 2,
                'estado' => 'PLANIFICADO', 'es_actual' => false,
                'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-12-15',
            ],
        ];

        foreach ($periodos as $p) {
            PeriodoAcademico::updateOrCreate(
                ['anio' => $p['anio'], 'ciclo' => $p['ciclo']], // respeta unique(anio,ciclo)
                [
                    'codigo'        => $p['codigo'],
                    'estado'        => $p['estado'],
                    'es_actual'     => $p['es_actual'],
                    'fecha_inicio'  => $p['fecha_inicio'],
                    'fecha_fin'     => $p['fecha_fin'],
                ]
            );
        }

        // Asegura que solo 2025-2 quede marcado como actual
        PeriodoAcademico::where('codigo', '!=', '2025-2')->update(['es_actual' => false]);
    }
}
