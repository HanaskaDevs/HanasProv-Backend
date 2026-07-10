<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
{
    return [
        'nombre_producto' => ['required', 'string', 'max:200'],
        'codigo_barras' => ['nullable', 'string', 'max:50'],
        'id_unidad_presentacion' => ['required', 'integer', 'exists:Unidad_Presentacion,Id_Unidad_Presentacion'],
        'precio' => ['nullable', 'numeric', 'min:0'],
    ];
}
}