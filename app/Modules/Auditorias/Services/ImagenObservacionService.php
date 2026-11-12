<?php

namespace App\Modules\Auditorias\Services;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\AuditoriaPregunta;
use App\Modules\Auditorias\Models\AuditoriaRespuesta;
use App\Modules\Auditorias\Models\AuditoriaRespuestaImagen;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\CalificacionRecepcionRespuesta;
use App\Modules\Auditorias\Models\CalificacionRecepcionRespuestaImagen;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Fotos adjuntas a las observaciones de Calidad (12-nov-2026).
 *
 * Un solo service para los dos formularios -auditorías y calificación de
 * recepciones- porque hace exactamente lo mismo en ambos: deja subir hasta
 * MAX_IMAGENES fotos por pregunta, las guarda en el repositorio de
 * archivos y las devuelve para mostrarlas. Lo único que cambia es de qué
 * tabla cuelga la fila de enlace.
 *
 * QUE NO HACE: decidir quién puede entrar. El permiso lo verifica el
 * service del módulo (AuditoriaService::verificarAcceso y
 * CalificacionRecepcionService::verificarAcceso) ANTES de llamar acá,
 * igual que con el resto de las acciones de esos formularios.
 */
class ImagenObservacionService
{
    /**
     * Tope de fotos por pregunta (pedido del usuario: "hasta 2 imágenes").
     *
     * Vive en el código y no en la base para poder cambiarlo sin migrar.
     * Si se cambia, hay que cambiar también MAX_IMAGENES_OBSERVACION en
     * src/modules/auditorias/types.ts del frontend.
     */
    public const MAX_IMAGENES = 2;

    /** Mismo tope por archivo que el resto del portal (4 MB). */
    public const MAX_KB = 4096;

    protected const DISCO = 'auditorias';

    // ------------------------------------------------------------------
    // Auditorías
    // ------------------------------------------------------------------

    /**
     * Adjunta una foto a la observación de una pregunta de la auditoría.
     *
     * Si la pregunta todavía no tiene fila de respuesta se crea vacía: es
     * lo mismo que ya pasaba al escribir una observación sin poner
     * puntaje, y una respuesta sin puntaje ni "No aplica" NO cuenta como
     * respondida al finalizar (ver AuditoriaService::finalizar).
     */
    public function subirEnAuditoria(Usuario $usuario, Auditoria $auditoria, int $idPregunta, UploadedFile $imagen): array
    {
        $pregunta = AuditoriaPregunta::whereHas(
            'seccion',
            fn ($q) => $q->where('Id_Tipo_Auditoria', $auditoria->Id_Tipo_Auditoria)
        )->findOrFail($idPregunta);

        return DB::transaction(function () use ($usuario, $auditoria, $pregunta, $imagen) {
            $respuesta = AuditoriaRespuesta::firstOrCreate(
                [
                    'Id_Auditoria' => $auditoria->Id_Auditoria,
                    'Id_Auditoria_Pregunta' => $pregunta->Id_Auditoria_Pregunta,
                ],
                ['No_Aplica' => false, 'Fecha_Modificacion' => now()]
            );

            $this->verificarCupo($respuesta->imagenes()->count());

            $archivo = $this->guardarArchivo(
                $usuario,
                $imagen,
                $auditoria->Id_Proveedor,
                'auditorias/' . $auditoria->Id_Auditoria . '/' . $pregunta->Id_Auditoria_Pregunta
            );

            $fila = AuditoriaRespuestaImagen::create([
                'Id_Auditoria_Respuesta' => $respuesta->Id_Auditoria_Respuesta,
                'Id_Archivo' => $archivo->Id_Archivo,
                'Activo' => true,
            ]);

            return $this->serializar(
                $fila->Id_Auditoria_Respuesta_Imagen,
                $archivo,
                '/api/auditorias/imagenes/' . $fila->Id_Auditoria_Respuesta_Imagen . '/ver'
            );
        });
    }

    /** Baja lógica: la fila de Archivo y el binario quedan como evidencia. */
    public function eliminarEnAuditoria(AuditoriaRespuestaImagen $imagen): void
    {
        $imagen->update(['Activo' => false]);
    }

    // ------------------------------------------------------------------
    // Calificación de recepciones
    // ------------------------------------------------------------------

    /**
     * Adjunta una foto a la observación de un parámetro de la recepción.
     *
     * A DIFERENCIA DE LAS AUDITORÍAS, acá el parámetro tiene que estar
     * respondido antes: Calificacion_Recepcion_Respuesta.Cumple y
     * Puntaje_Obtenido son NOT NULL (ver la migración del módulo), así que
     * no existe la "respuesta vacía" de la que colgar la foto. El frontend
     * lo refleja pidiendo primero el Sí/No.
     */
    public function subirEnRecepcion(Usuario $usuario, CalificacionRecepcion $calificacion, int $idParametro, UploadedFile $imagen): array
    {
        $respuesta = CalificacionRecepcionRespuesta::where('Id_Calificacion_Recepcion', $calificacion->Id_Calificacion_Recepcion)
            ->where('Id_Recepcion_Parametro', $idParametro)
            ->first();

        if (! $respuesta) {
            throw ValidationException::withMessages([
                'imagen' => ['Primero responde el parámetro; después puedes adjuntar fotos a su observación.'],
            ]);
        }

        return DB::transaction(function () use ($usuario, $calificacion, $respuesta, $idParametro, $imagen) {
            $this->verificarCupo($respuesta->imagenes()->count());

            $archivo = $this->guardarArchivo(
                $usuario,
                $imagen,
                $calificacion->Id_Proveedor,
                'recepciones/' . $calificacion->Id_Calificacion_Recepcion . '/' . $idParametro
            );

            $fila = CalificacionRecepcionRespuestaImagen::create([
                'Id_Calificacion_Recepcion_Respuesta' => $respuesta->Id_Calificacion_Recepcion_Respuesta,
                'Id_Archivo' => $archivo->Id_Archivo,
                'Activo' => true,
            ]);

            return $this->serializar(
                $fila->Id_Calificacion_Recepcion_Respuesta_Imagen,
                $archivo,
                '/api/calificacion-recepciones/imagenes/' . $fila->Id_Calificacion_Recepcion_Respuesta_Imagen . '/ver'
            );
        });
    }

    public function eliminarEnRecepcion(CalificacionRecepcionRespuestaImagen $imagen): void
    {
        $imagen->update(['Activo' => false]);
    }

    // ------------------------------------------------------------------
    // Comunes
    // ------------------------------------------------------------------

    /**
     * Ruta absoluta del binario, para devolverlo con response()->file().
     * Las fotos NO son públicas: salen siempre por un endpoint
     * autenticado, igual que las de los reclamos.
     */
    public function rutaFisica(Model $imagen): string
    {
        $archivo = $imagen->archivo;

        if (! $archivo || ! Storage::disk(self::DISCO)->exists($archivo->Ruta_Almacenamiento)) {
            abort(404, 'La imagen ya no está disponible.');
        }

        return Storage::disk(self::DISCO)->path($archivo->Ruta_Almacenamiento);
    }

    /**
     * Las fotos de una respuesta, listas para el frontend.
     *
     * @param  \Illuminate\Support\Collection<int, Model>|\Illuminate\Database\Eloquent\Collection<int, Model>  $imagenes
     */
    public function serializarVarias($imagenes, string $prefijoUrl): array
    {
        return collect($imagenes)
            ->filter(fn (Model $i) => $i->archivo !== null)
            ->map(fn (Model $i) => $this->serializar(
                (int) $i->getKey(),
                $i->archivo,
                $prefijoUrl . '/' . $i->getKey() . '/ver'
            ))
            ->values()
            ->all();
    }

    protected function verificarCupo(int $yaTiene): void
    {
        if ($yaTiene >= self::MAX_IMAGENES) {
            throw ValidationException::withMessages([
                'imagen' => ['Máximo ' . self::MAX_IMAGENES . ' imágenes por pregunta. Elimina una para subir otra.'],
            ]);
        }
    }

    /**
     * Guarda el binario y su fila en Archivo. Mismo procedimiento que
     * ReclamoService::guardarImagen: primero la fila (para tener el id con
     * el que se nombra el archivo), después el binario, y recién entonces
     * se completa la ruta.
     */
    protected function guardarArchivo(Usuario $usuario, UploadedFile $imagen, ?int $idProveedor, string $carpeta): Archivo
    {
        $archivo = Archivo::create([
            'Id_Proveedor' => $idProveedor,
            'Nombre_Original' => $imagen->getClientOriginalName(),
            'Ruta_Almacenamiento' => '',
            'Hash_Archivo' => hash_file('sha256', $imagen->getRealPath()),
            'Tipo_Mime' => $imagen->getMimeType(),
            'Tamano_Bytes' => $imagen->getSize(),
            'Categoria_Archivo' => 'auditorias',
            'Id_Usuario_Carga' => $usuario->Id_Usuario,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        $extension = $imagen->getClientOriginalExtension() ?: 'jpg';
        $nombreFisico = $archivo->Id_Archivo . '.' . $extension;

        Storage::disk(self::DISCO)->putFileAs($carpeta, $imagen, $nombreFisico);

        $archivo->update(['Ruta_Almacenamiento' => $carpeta . '/' . $nombreFisico]);

        return $archivo;
    }

    protected function serializar(int $id, Archivo $archivo, string $url): array
    {
        return [
            'id' => $id,
            'nombre' => $archivo->Nombre_Original,
            'url' => $url,
        ];
    }
}
