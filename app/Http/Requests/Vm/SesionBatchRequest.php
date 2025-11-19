<?php

namespace App\Http\Requests\Vm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SesionBatchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // Normaliza HH:mm:ss -> HH:mm; y 8:00 -> 08:00
        $norm = static function ($v) {
            if (!is_string($v)) return $v;
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $v)) {
                return substr($v, 0, 5); // HH:mm
            }
            if (preg_match('/^\d{1}:\d{2}$/', $v)) {
                return '0' . $v; // 8:00 -> 08:00
            }
            return $v;
        };

        if ($this->has('hora_inicio')) {
            $this->merge(['hora_inicio' => $norm($this->input('hora_inicio'))]);
        }
        if ($this->has('hora_fin')) {
            $this->merge(['hora_fin' => $norm($this->input('hora_fin'))]);
        }

        if ($this->has('niveles')) {
            $niveles = collect((array) $this->input('niveles'))
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $this->merge(['niveles' => $niveles]);
        }
    }

    public function rules(): array
    {
        return [
            'mode'         => ['required', Rule::in(['range','list'])],

            'hora_inicio'  => ['required','date_format:H:i'],
            'hora_fin'     => ['required','date_format:H:i','after:hora_inicio'],

            'fecha_inicio' => ['required_if:mode,range','date'],
            'fecha_fin'    => ['required_if:mode,range','date','after_or_equal:fecha_inicio'],
            'dias_semana'  => ['nullable','array'],
            'dias_semana.*'=> ['nullable'],

            'fechas'       => ['required_if:mode,list','array','min:1'],
            'fechas.*'     => ['date'],

            'niveles'      => ['sometimes','array','min:1'],
            'niveles.*'    => ['integer','between:1,10','distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'hora_inicio.date_format' => 'El campo hora_inicio debe tener el formato HH:mm.',
            'hora_fin.date_format'    => 'El campo hora_fin debe tener el formato HH:mm.',
            'hora_fin.after'          => 'La hora_fin debe ser mayor a hora_inicio.',
            'niveles.array'           => 'El campo niveles debe ser un arreglo.',
            'niveles.min'             => 'Debe indicar al menos un nivel si envía el campo.',
            'niveles.*.between'       => 'Cada nivel debe estar entre 1 y 10.',
            'niveles.*.distinct'      => 'Los niveles no deben repetirse.',
        ];
    }

    public function attributes(): array
    {
        // Evita que "hora_inicio" aparezca como "hora inicio"
        return [
            'hora_inicio' => 'hora_inicio',
            'hora_fin'    => 'hora_fin',
        ];
    }
}
