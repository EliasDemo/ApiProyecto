<?php

namespace Database\Seeders;

use App\Models\VmCategoriaEvento;
use Illuminate\Database\Seeder;

class VmCategoriasEventoSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = [
            [
                'nombre'      => 'Eventos Académicos',
                'descripcion' => 'Congresos, seminarios, jornadas, clases magistrales y otras actividades orientadas al fortalecimiento académico de los estudiantes y docentes.',
            ],
            [
                'nombre'      => 'Eventos Espirituales',
                'descripcion' => 'Semanas de oración, semanas de énfasis espiritual, vigilias, cultos especiales y programas organizados por Capellanía y la Iglesia Universitaria.',
            ],
            [
                'nombre'      => 'Eventos Culturales y Artísticos',
                'descripcion' => 'Festivales de música, concursos de talento, presentaciones de coros y orquestas, y actividades que promueven el arte y la cultura en la comunidad universitaria.',
            ],
            [
                'nombre'      => 'Eventos Deportivos y Recreativos',
                'descripcion' => 'Campeonatos internos, olimpiadas, torneos interfacultades e intersedes, así como actividades recreativas para promover la vida saludable.',
            ],
            [
                'nombre'      => 'Eventos de Investigación e Innovación',
                'descripcion' => 'Congresos científicos, ferias de investigación, exposiciones de proyectos, hackatones y otras actividades organizadas por los institutos y direcciones de investigación.',
            ],
            [
                'nombre'      => 'Proyección Social y Extensión Universitaria',
                'descripcion' => 'Campañas médicas, brigadas de ayuda, proyectos comunitarios, programas misioneros y actividades de responsabilidad social desarrolladas por la universidad.',
            ],
            [
                'nombre'      => 'Vida Universitaria y Bienestar',
                'descripcion' => 'Inducción de cachimbos, aniversarios de facultades y escuelas, ferias vocacionales, programas de bienestar estudiantil y actividades de integración.',
            ],
        ];

        foreach ($categorias as $cat) {
            VmCategoriaEvento::firstOrCreate(
                ['nombre' => $cat['nombre']],
                ['descripcion' => $cat['descripcion']]
            );
        }
    }
}
