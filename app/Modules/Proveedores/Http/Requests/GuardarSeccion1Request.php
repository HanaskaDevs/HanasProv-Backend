<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sección 1: Información del Proveedor.
 * Ruc/Razon_Social/Email son el mínimo indispensable; el resto queda
 * opcional porque en el esquema real todas las columnas son nullable.
 */
class GuardarSeccion1Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['required', 'string', 'size:13'],
            'clase_contribuyente' => ['nullable', 'string', 'max:50'],
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'ciudad' => ['nullable', 'string', 'max:50'],
            'pagina_web' => ['nullable', 'string', 'max:300'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],

            'representante_legal' => ['nullable', 'string', 'max:100'],
            'correo_representante' => ['nullable', 'email', 'max:200'],
            'telefono_representante' => ['nullable', 'string', 'max:10'],

            'contacto_venta' => ['nullable', 'string', 'max:100'],
            'correo_venta' => ['nullable', 'email', 'max:200'],
            'telefono_contacto_venta' => ['nullable', 'string', 'max:10'],

            'contacto_calidad' => ['nullable', 'string', 'max:100'],
            'correo_calidad' => ['nullable', 'email', 'max:200'],
            'telefono_contacto_calidad' => ['nullable', 'string', 'max:10'],

            'contacto_contabilidad' => ['nullable', 'string', 'max:100'],
            'correo_contabilidad' => ['nullable', 'email', 'max:200'],
            'telefono_contabilidad' => ['nullable', 'string', 'max:200'],
        ];
    }
}
