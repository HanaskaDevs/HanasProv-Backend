<?php

namespace App\Modules\Configuraciones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarHomeSlideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eyebrow' => ['required', 'string', 'max:50'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['required', 'string', 'max:500'],
            'orden' => ['nullable', 'integer'],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,webm', 'max:20480'],
        ];
    }
}