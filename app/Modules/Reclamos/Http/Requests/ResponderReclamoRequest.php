<?php

namespace App\Modules\Reclamos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResponderReclamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mensaje' => ['required', 'string', 'max:2000'],
            'imagenes' => ['nullable', 'array', 'max:5'],
            // 'image' a secas acepta SVG, y un SVG es un XML que puede traer
            // JavaScript adentro. Como estas imágenes se sirven con
            // Content-Disposition: inline (ver ReclamoService::verImagen), ese
            // script se ejecutaría en el navegador de quien abra el reclamo.
            // Por eso la lista es explícita: formatos de foto y nada más.
            'imagenes.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}