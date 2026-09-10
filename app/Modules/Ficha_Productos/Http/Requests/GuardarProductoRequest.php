<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'peso' => ['nullable', 'numeric', 'min:0'],
            'volumen' => ['nullable', 'numeric', 'min:0'],
            'unidad_por_caja' => ['nullable', 'integer', 'min:1'],

            /*
             * Grupo de producto: OPCIONAL y MÚLTIPLE. Se puede no mandar
             * nada, mandar uno, o mandarlos todos.
             *
             * 'present' y no 'nullable' a secas en el arreglo: al editar,
             * mandar 'grupos' => [] es la forma de decir "quítale todos los
             * grupos", y eso hay que poder distinguirlo de no mandar el
             * campo (ver ProductoService::actualizar).
             *
             * El exists mira solo los ACTIVOS: un grupo que Sistemas dio de
             * baja no se puede volver a asignar, aunque siga existiendo en
             * los productos que ya lo tenían.
             */
            'grupos' => ['sometimes', 'array'],
            'grupos.*' => [
                'integer',
                Rule::exists('Grupo_Producto', 'Id_Grupo_Producto')->where('Activo', 1),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'grupos.*.exists' => 'Uno de los grupos de producto seleccionados ya no está disponible.',
        ];
    }
}