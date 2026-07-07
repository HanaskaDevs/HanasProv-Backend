<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['required', 'string', 'size:13'],
            'razon_social' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:150'],
            // TODO: completar con el resto de campos de la Ficha de Proveedor
            // (secciones progresivas: clases, categorías, certificaciones, ubicación)
        ];
    }
}
