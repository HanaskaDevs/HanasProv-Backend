<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sección 1: Información del Proveedor (Datos Generales + Contactos).
 * Todo es obligatorio, con la única excepción de Página web.
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
            'clase_contribuyente' => ['required', 'string', 'max:50'],
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:150'],
            'telefono' => ['required', 'string', 'max:20'],
            'direccion' => ['required', 'string', 'max:300'],
            'ciudad' => ['required', 'string', 'max:50'],
            'pagina_web' => ['nullable', 'string', 'max:300'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],

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