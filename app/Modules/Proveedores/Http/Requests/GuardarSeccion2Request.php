<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sección 2: Clase de Proveedor (multi-select).
 */
class GuardarSeccion2Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_clases' => ['required', 'array', 'min:1'],
            'id_clases.*' => ['integer', 'exists:Clase_Proveedor,Id_Clase_Proveedor'],
        ];
    }
}
