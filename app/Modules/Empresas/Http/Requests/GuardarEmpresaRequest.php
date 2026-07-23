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