<?php

namespace App\Shared;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validación y limpieza de los PDF que suben los proveedores (16-oct-2026).
 *
 * EL PROBLEMA: 'mimes:pdf' decide por el contenido con libmagic, que solo
 * reconoce un PDF si "%PDF-" está en el byte 0. Llegó un registro sanitario
 * con un salto de línea (\r\n) ANTES de "%PDF-1.7": Acrobat, Edge y Chrome lo
 * abren sin problema, pero el servidor lo veía como application/octet-stream
 * y lo rechazaba. El proveedor solo veía "verifica que sea PDF", con un PDF
 * que su computadora abría bien.
 *
 * EL CRITERIO: la especificación (y Acrobat) admiten basura antes del
 * encabezado siempre que "%PDF-" aparezca dentro de los primeros 1024
 * bytes. Es lo mismo que acepta acá la regla. Todo lo demás (una imagen, un
 * .docx renombrado) se sigue rechazando.
 *
 * Y SE GUARDA LIMPIO: lo que haya antes de "%PDF-" se recorta antes de
 * escribir al repositorio. Las posiciones internas del PDF (la tabla xref)
 * cuentan desde el encabezado, no desde el inicio del archivo: con el
 * recorte quedan exactas (comprobado con el archivo que falló), y el visor
 * del portal y el detector de tipo lo reconocen sin más.
 */
class ArchivoPdf implements ValidationRule
{
    public const FIRMA = '%PDF-';

    /** Hasta dónde se busca la firma, igual que Acrobat. */
    public const BYTES_TOLERADOS = 1024;

    /**
     * Acepta TODO lo que ya aceptaba 'mimes:pdf' (el detector de tipo dice
     * PDF) y además los que tienen la firma dentro de los primeros 1024
     * bytes. Es estrictamente más permisiva que antes: nada que pasaba
     * ahora se rechaza.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $esPdf = $value instanceof UploadedFile
            && ($value->getMimeType() === 'application/pdf' || self::posicionDeLaFirma($value) !== null);

        if (! $esPdf) {
            $fail('El archivo no es un PDF válido. Ábrelo y vuelve a guardarlo como PDF, o imprímelo como PDF, e intenta de nuevo.');
        }
    }

    /** Dónde empieza "%PDF-" (0 en un PDF normal), o null si no está al principio. */
    public static function posicionDeLaFirma(UploadedFile $archivo): ?int
    {
        $ruta = $archivo->getRealPath();

        if ($ruta === false || ! is_readable($ruta)) {
            return null;
        }

        $inicio = (string) file_get_contents($ruta, false, null, 0, self::BYTES_TOLERADOS);
        $posicion = strpos($inicio, self::FIRMA);

        return $posicion === false ? null : $posicion;
    }

    /**
     * El mismo archivo sin lo que haya antes de "%PDF-". Si ya empieza con
     * la firma (el caso normal) devuelve el original sin copiar nada.
     */
    public static function limpiar(UploadedFile $archivo): UploadedFile
    {
        $posicion = self::posicionDeLaFirma($archivo);

        if (! $posicion) {
            return $archivo;
        }

        $temporal = tempnam(sys_get_temp_dir(), 'pdf_');
        $origen = fopen($archivo->getRealPath(), 'rb');
        $destino = fopen($temporal, 'wb');

        fseek($origen, $posicion);
        stream_copy_to_stream($origen, $destino);

        fclose($origen);
        fclose($destino);

        // PHP borra solo los temporales de la subida; este es nuestro.
        register_shutdown_function(static fn () => @unlink($temporal));

        return new UploadedFile($temporal, $archivo->getClientOriginalName(), 'application/pdf', null, true);
    }
}
