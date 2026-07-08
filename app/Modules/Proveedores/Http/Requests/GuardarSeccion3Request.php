<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sección 3: Categoría de Productos/Servicios (multi-select).
 */
class GuardarSeccion3Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_categorias' => ['required', 'array', 'min:1'],
            'id_categorias.*' => ['integer', 'exists:Categoria_Producto,Id_Categoria_Producto'],
        ];
    }
}
