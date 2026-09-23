<?php

namespace App\Modules\Documentos_Proveedor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:4096'],
            /*
             * MÍNIMO UN MES DE VIGENCIA (pedido del usuario, 23-sep-2026).
             *
             * Antes solo se validaba que fuera "una fecha", así que se podía
             * cargar un documento con fecha del año pasado y el portal lo
             * daba por presentado. Pasó de verdad: un certificado con
             * caducidad 2026-01-25 subido en septiembre.
             *
             * El mes de margen no es un capricho: el ciclo de avisos empieza
             * 30 días antes del vencimiento (VencimientoDocumentosService::
             * DIAS_PRIMER_AVISO). Un documento que entra con menos de eso
             * nace disparando el aviso de "por vencer" el mismo día que se
             * carga.
             *
             * 'date_format:Y-m-d' además del 'date': obliga a día, mes y año
             * completos y descarta un "2026" suelto, que 'date' aceptaría.
             */
            'fecha_caducidad' => [
                'nullable',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:'.now()->addMonth()->toDateString(),
            ],
            'nombre_documento' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_caducidad.after_or_equal' => 'La fecha de caducidad debe ser de al menos un mes a futuro (desde el '
                .now()->addMonth()->format('d/m/Y').' en adelante). No se pueden cargar documentos vencidos o por vencer.',
            'fecha_caducidad.date_format' => 'Indica la fecha completa: día, mes y año.',
        ];
    }
}