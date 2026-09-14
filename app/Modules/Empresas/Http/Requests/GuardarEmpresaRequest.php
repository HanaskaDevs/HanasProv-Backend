<?php

namespace App\Modules\Empresas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El nombre de la empresa se guarda SIEMPRE en mayúsculas.
     *
     * Se normaliza acá, en prepareForValidation, y no en el controller: así
     * vale para crear y para editar sin repetir la lógica, y las reglas de
     * unicidad comparan el valor ya normalizado (si no, "Caterfood" y
     * "CATERFOOD" pasarían como dos empresas distintas).
     *
     * mb_strtoupper y no strtoupper: strtoupper no toca las tildes ni la Ñ,
     * y dejaría "COMPAÑIA ANDALUCÍA" como "COMPAÑíA ANDALUCíA".
     */
    protected function prepareForValidation(): void
    {
        foreach (['razon_social', 'nombre_comercial'] as $campo) {
            if ($this->filled($campo)) {
                $this->merge([$campo => mb_strtoupper(trim((string) $this->input($campo)), 'UTF-8')]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'razon_social' => ['required', 'string', 'max:200'],
            'ruc' => [
                'required', 'string', 'size:13',
                Rule::unique('Empresa', 'Ruc')->ignore($this->route('empresa')?->Id_Empresa, 'Id_Empresa'),
            ],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'empresa_bc' => ['nullable', 'string', 'max:50'],
        ];
    }
}