<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Para un proveedor YA APROBADO actualizando solo sus datos de
 * contacto -> mismas reglas que esos mismos campos tienen en
 * GuardarSeccion1Request (todos obligatorios), pero sin el resto de
 * Datos Generales, que acá no se puede tocar.
 */
class GuardarContactosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'representante_legal' => ['required', 'string', 'max:100'],
            'correo_representante' => ['required', 'email', 'max:200'],
            'telefono_representante' => ['required', 'string', 'max:10'],

            'contacto_venta' => ['required', 'string', 'max:100'],
            'correo_venta' => ['required', 'email', 'max:200'],
            'telefono_contacto_venta' => ['required', 'string', 'max:10'],

            'contacto_calidad' => ['required', 'string', 'max:100'],
            'correo_calidad' => ['required', 'email', 'max:200'],
            'telefono_contacto_calidad' => ['required', 'string', 'max:10'],

            'contacto_contabilidad' => ['required', 'string', 'max:100'],
            'correo_contabilidad' => ['required', 'email', 'max:200'],
            'telefono_contabilidad' => ['required', 'string', 'max:200'],
        ];
    }
}