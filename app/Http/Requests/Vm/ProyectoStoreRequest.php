<?php

namespace App\Http\Requests\Vm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProyectoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza `tipo` y `niveles` antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $tipo = $this->has('tipo') ? strtoupper((string) $this->input('tipo')) : null;

        // Normaliza niveles a enteros únicos y ordenados
        $niveles = $this->has('niveles')
            ? collect((array) $this->input('niveles'))
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->sort()
                ->values()
                ->all()
            : null;

        $merge = [];
        if (!is_null($tipo)) {
            $merge['tipo'] = $tipo;
        }
        if (!is_null($niveles)) {
            $merge['niveles'] = $niveles;
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $ep  = $this->input('ep_sede_id');
        $per = $this->input('periodo_id');

        return [
            'ep_sede_id'  => ['required','integer','exists:ep_sede,id'],
            'periodo_id'  => ['required','integer','exists:periodos_academicos,id'],

            // Único global si lo envían; si no, se genera en el controller
            'codigo'      => ['nullable','string','max:255','unique:vm_proyectos,codigo'],

            'titulo'      => ['required','string','max:255'],
            'descripcion' => ['nullable','string'],

            // Acepta PROYECTO por compat (se trata como VINCULADO)
            'tipo'        => ['required', Rule::in(['VINCULADO','LIBRE','PROYECTO'])],
            'modalidad'   => ['required', Rule::in(['PRESENCIAL','VIRTUAL','MIXTA'])],

            // 👇 Multiciclo:
            // - Requerido si VINCULADO/PROYECTO
            // - Prohibido si LIBRE
            'niveles'     => [
                'required_if:tipo,VINCULADO,PROYECTO',
                'prohibited_unless:tipo,VINCULADO,PROYECTO',
                'array',
                'min:1',
            ],
            'niveles.*'   => [
                'integer','between:1,10','distinct',
                Rule::unique('vm_proyecto_ciclos','nivel')
                    ->where(fn ($q) => $q->where('ep_sede_id', $ep)
                                          ->where('periodo_id', $per)),
            ],

            'horas_planificadas'         => ['required','integer','min:1','max:32767'],
            'horas_minimas_participante' => ['nullable','integer','min:0','max:32767'],
        ];
    }

    public function messages(): array
    {
        return [
            'niveles.required_if'         => 'Debe indicar al menos un ciclo (nivel) para proyectos vinculados.',
            'niveles.prohibited_unless'   => 'Los proyectos de tipo LIBRE no deben incluir niveles.',
            'niveles.array'               => 'El campo niveles debe ser un arreglo.',
            'niveles.min'                 => 'Debe indicar al menos un nivel.',
            'niveles.*.between'           => 'Cada nivel debe estar entre 1 y 10.',
            'niveles.*.distinct'          => 'Los niveles no deben repetirse.',
            'niveles.*.unique'            => 'El nivel :input ya está ocupado en este período y sede.',
        ];
    }
}
