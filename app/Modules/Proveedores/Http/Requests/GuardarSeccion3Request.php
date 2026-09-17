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
            // Casilla "acepto las Políticas de Hanaska". Solo se exige cuando
            // este guardado deja la ficha al 100% y el proveedor no la había
            // aceptado antes; esa decisión vive en FichaProveedorService, que
            // es quien sabe el estado de las otras secciones.
            'acepta_politicas' => ['sometimes', 'boolean'],
        ];
    }
}
