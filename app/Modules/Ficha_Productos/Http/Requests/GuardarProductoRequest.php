<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
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
            // "Unidades x Masterpack (caja)" en pantalla. La columna se
            // sigue llamando Unidad_Por_Caja: es el mismo dato de siempre,
            // solo cambió el nombre que ve el usuario (12-sep-2026).
            'unidad_por_caja' => ['nullable', 'integer', 'min:1'],

            /*
             * Cuánto trae el paquete. OBLIGATORIO solo cuando la unidad de
             * presentación elegida es "Paquete": un paquete sin decir qué
             * contiene no es un dato, es un campo a medio llenar. Con
             * cualquier otra unidad ni siquiera se muestra.
             *
             * El id de "Paquete" se resuelve del catálogo y no se escribe
             * como número: las unidades se administran desde Catálogos y
             * sus ids pueden ser otros en otro entorno.
             */
            'contenido_paquete' => $this->esPaquete()
                ? ['required', 'integer', 'min:1']
                : ['nullable', 'integer', 'min:1'],

            /*
             * Medidas en CENTÍMETROS, todas opcionales (hay 300 productos
             * ya cargados sin ellas; exigirlas los dejaría sin poder
             * editarse).
             *
             * 'volumen' NO se recibe más: se calcula de las medidas de la
             * unidad (ver ProductoService::volumenDeLaUnidad). Aceptarlo
             * del cliente permitiría guardar un volumen que contradiga a
             * sus propias medidas.
             */
            'masterpack_largo_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'masterpack_ancho_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'masterpack_alto_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'unidad_largo_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'unidad_ancho_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'unidad_alto_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

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
            'contenido_paquete.required' => 'Indica cuántas unidades trae el paquete.',
        ];
    }

    public function attributes(): array
    {
        return [
            'unidad_por_caja' => 'unidades x masterpack',
            'contenido_paquete' => 'contenido x paquete',
            'masterpack_largo_cm' => 'largo del masterpack',
            'masterpack_ancho_cm' => 'ancho del masterpack',
            'masterpack_alto_cm' => 'alto del masterpack',
            'unidad_largo_cm' => 'largo de la unidad',
            'unidad_ancho_cm' => 'ancho de la unidad',
            'unidad_alto_cm' => 'alto de la unidad',
        ];
    }

    /** ¿La unidad de presentación elegida es "Paquete"? */
    protected function esPaquete(): bool
    {
        $idPaquete = UnidadPresentacion::where('Nombre_Unidad', 'Paquete')->value('Id_Unidad_Presentacion');

        return $idPaquete !== null && (int) $this->input('id_unidad_presentacion') === (int) $idPaquete;
    }
}