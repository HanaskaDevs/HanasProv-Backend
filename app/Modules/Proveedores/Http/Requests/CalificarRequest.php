<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalificarRequest extends FormRequest
{
    /**
     * Tope del motivo al calificar un DOCUMENTO (13-nov-2026, pedido del
     * usuario). Tiene que coincidir con la columna
     * Documento_Proveedor.Comentario_Calificacion (NVARCHAR(250), migración
     * 2026_11_13_110000) y con la pantalla (SeccionCalificarDocumentos). Antes
     * se aceptaban 1000 contra una columna de 50 y el guardado fallaba.
     */
    public const MAX_MOTIVO_DOCUMENTO = 250;

    /** Productos: se deja como estaba. */
    public const MAX_MOTIVO_OTROS = 1000;

    public function authorize(): bool
    {
        return true; // La autorización real (rol Admin/Sistemas) se valida en el Service.
    }

    /** Este request lo usan documentos y productos; se distingue por el parámetro de la ruta. */
    private function esDocumento(): bool
    {
        return $this->route('documentoProveedor') !== null;
    }

    public function rules(): array
    {
        $maximo = $this->esDocumento() ? self::MAX_MOTIVO_DOCUMENTO : self::MAX_MOTIVO_OTROS;

        return [
            'aprobado' => ['required', 'boolean'],
            // La observación es obligatoria SOLO al rechazar (aprobado=false):
            // es la retroalimentación que el proveedor va a ver para saber
            // qué corregir. Al aprobar, es opcional.
            'observacion' => ['nullable', 'string', "max:{$maximo}", 'required_if:aprobado,false'],
        ];
    }

    public function messages(): array
    {
        $maximo = $this->esDocumento() ? self::MAX_MOTIVO_DOCUMENTO : self::MAX_MOTIVO_OTROS;

        return [
            'observacion.required_if' => 'Indica una observación para explicarle al proveedor por qué se rechaza.',
            'observacion.max' => "El motivo puede tener como máximo {$maximo} caracteres.",
        ];
    }
}
