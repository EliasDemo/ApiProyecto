<?php

namespace App\Http\Requests\Vm;

use Illuminate\Foundation\Http\FormRequest;

class EventoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización real la manejas con middleware / EpScopeService
        return true;
    }

    public function rules(): array
    {
        return [
            'periodo_id'          => ['required', 'integer', 'exists:periodos_academicos,id'],
            'categoria_evento_id' => ['nullable', 'integer', 'exists:vm_categorias_evento,id'],

            'codigo'             => ['nullable', 'string', 'max:255', 'unique:vm_eventos,codigo'],
            'titulo'             => ['required', 'string', 'max:255'],
            'subtitulo'          => ['nullable', 'string', 'max:255'],
            'descripcion_corta'  => ['nullable', 'string'],
            'descripcion_larga'  => ['nullable', 'string'],

            'modalidad'          => ['required', 'in:PRESENCIAL,VIRTUAL,MIXTA'],
            'lugar_detallado'    => ['nullable', 'string', 'max:255'],
            'url_imagen_portada' => ['nullable', 'url'],
            'url_enlace_virtual' => ['nullable', 'url'],

            'requiere_inscripcion' => ['boolean'],
            'cupo_maximo'          => ['nullable', 'integer', 'min:1'],

            'inscripcion_desde'    => ['nullable', 'date'],
            'inscripcion_hasta'    => ['nullable', 'date', 'after_or_equal:inscripcion_desde'],

            // Sesiones
            'sesiones'                 => ['required', 'array', 'min:1'],
            'sesiones.*.fecha'         => ['required', 'date'],
            'sesiones.*.hora_inicio'   => ['required', 'date_format:H:i'], // Angular envía HH:mm
            'sesiones.*.hora_fin'      => ['required', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'periodo_id.required' => 'Debes seleccionar un período académico.',
            'periodo_id.exists'   => 'El período académico seleccionado no existe.',

            'titulo.required'     => 'El título del evento es obligatorio.',

            'sesiones.required'   => 'Debes registrar al menos una sesión.',
            'sesiones.min'        => 'Debes registrar al menos una sesión.',

            'sesiones.*.fecha.required'       => 'Cada sesión debe tener una fecha.',
            'sesiones.*.hora_inicio.required' => 'Cada sesión debe tener hora de inicio.',
            'sesiones.*.hora_fin.required'    => 'Cada sesión debe tener hora de fin.',

            'sesiones.*.hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:mm.',
            'sesiones.*.hora_fin.date_format'    => 'La hora de fin debe tener el formato HH:mm.',
        ];
    }
}
