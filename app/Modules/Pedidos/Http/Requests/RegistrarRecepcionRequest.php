<?php

namespace App\Modules\Pedidos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarRecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_recepcion' => ['required', 'date'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.id_detalle_pedido_compra' => ['required', 'integer'],
            'lineas.*.cantidad_recibida' => ['required', 'numeric', 'min:0'],
            'lineas.*.recepcion_completa' => ['required', 'boolean'],
            'lineas.*.observacion' => ['nullable', 'string', 'max:500'],
            'lineas.*.imagenes' => ['nullable', 'array', 'max:3'],
            'lineas.*.imagenes.*' => ['file', 'image', 'max:5120'],
        ];
    }
}