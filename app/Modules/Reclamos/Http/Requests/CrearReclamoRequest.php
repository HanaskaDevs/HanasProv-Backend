<?php

namespace App\Modules\Reclamos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearReclamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proveedor' => ['required', 'integer'],
            'asunto' => ['required', 'string', 'max:200'],
            'tipo_reclamo' => ['required', 'string', 'in:Calidad,Salubridad,Inocuidad'],
            'impacto_proveedor' => ['required', 'string', 'in:Alto,Medio,Bajo'],
            'mensaje' => ['required', 'string', 'max:2000'],
            'destinatarios' => ['required', 'array', 'min:1'],
            'destinatarios.*.rol_contacto' => ['required', 'string', 'max:50'],
            'destinatarios.*.nombre_contacto' => ['nullable', 'string', 'max:200'],
            'destinatarios.*.email' => ['required', 'email', 'max:150'],
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