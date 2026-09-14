<?php

namespace App\Shared;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Cuando se reemplaza un documento (ficha o producto), qué pasa con el
 * archivo viejo depende de POR QUÉ se está reemplazando:
 *
 * - Si el archivo viejo ya fue "Rechazado" por un admin -> es una
 *   corrección real de algo que alguien revisó y juzgó. Vale la pena
 *   guardarlo como respaldo/auditoría -> se archiva en "historico/".
 *
 * - Si el archivo viejo nunca fue revisado (Pendiente/null, típicamente
 *   un error de tipeo antes de registrar/enviar nada, o -en Productos-
 *   un archivo subido DURANTE una corrección en curso, que todavía no
 *   vio ningún admin) -> no aporta nada guardarlo, se borra directo.
 *
 * OJO con Productos específicamente: el estado "Rechazado" vive en el
 * PRODUCTO (no en cada documento), y a propósito NO se resetea a
 * "Pendiente" hasta que el proveedor confirma con "Registrar productos
 * actualizados" -> eso significa que, DURANTE una corrección, el
 * producto se queda "Rechazado" aunque el proveedor suba 2 o 3 archivos
 * seguidos para el mismo documento, probando distintas versiones. Si
 * solo miráramos el estado del producto, TODOS esos archivos
 * intermedios (que nadie llegó a revisar) terminarían en "historico/"
 * por error. Por eso, para decidir, además del estado se compara la
 * fecha de creación del documento viejo contra la fecha en que se
 * calificó el producto: si el documento es de ANTES de esa
 * calificación, es el que el admin realmente vio -> historico. Si es de
 * DESPUÉS (subido durante la corrección en curso), nadie lo revisó
 * todavía -> se borra directo.
 */
trait MueveArchivoAHistorico
{
    protected function moverArchivoAHistorico(string $disco, ?string $rutaActual): ?string
    {
        if (! $rutaActual || ! Storage::disk($disco)->exists($rutaActual)) {
            return null;
        }

        $nombreArchivo = basename($rutaActual);
        $carpeta = dirname($rutaActual);
        $rutaHistorico = "{$carpeta}/historico/{$nombreArchivo}";

        Storage::disk($disco)->move($rutaActual, $rutaHistorico);

        return $rutaHistorico;
    }

    protected function eliminarArchivoDirecto(string $disco, ?string $rutaActual): void
    {
        if ($rutaActual && Storage::disk($disco)->exists($rutaActual)) {
            Storage::disk($disco)->delete($rutaActual);
        }
    }

    /**
     * Uso simple (Documentos): cada documento tiene su propio
     * Estado_Calificacion, que arranca en null en cada fila nueva -> con
     * eso solo alcanza para decidir bien, sin comparar fechas.
     */
    protected function archivarOEliminarSegunEstado(string $disco, ?string $rutaActual, ?string $estadoCalificacionAnterior): ?string
    {
        if ($estadoCalificacionAnterior === 'Rechazado') {
            return $this->moverArchivoAHistorico($disco, $rutaActual);
        }

        $this->eliminarArchivoDirecto($disco, $rutaActual);

        return null;
    }

    /**
     * Uso para Productos: el estado vive en el producto (compartido
     * entre todos sus documentos) y no se resetea hasta confirmar la
     * corrección -> además del estado, hay que comparar cuándo se creó
     * ESTE documento puntual contra cuándo se calificó el producto, para
     * no mandar a histórico algo que nadie llegó a ver.
     */
    protected function archivarOEliminarSegunEstadoProducto(
        string $disco,
        ?string $rutaActual,
        ?string $estadoCalificacionProducto,
        ?Carbon $fechaCreacionDocumentoViejo,
        ?Carbon $fechaCalificacionProducto
    ): ?string {
        $fueRevisadoRealmente = $estadoCalificacionProducto === 'Rechazado'
            && $fechaCreacionDocumentoViejo !== null
            && $fechaCalificacionProducto !== null
            && $fechaCreacionDocumentoViejo->lessThanOrEqualTo($fechaCalificacionProducto);

        if ($fueRevisadoRealmente) {
            return $this->moverArchivoAHistorico($disco, $rutaActual);
        }

        $this->eliminarArchivoDirecto($disco, $rutaActual);

        return null;
    }
}