<?php

namespace App\Shared;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Comprueba que un archivo del repositorio se pueda entregar, y si no,
 * distingue POR QUÉ.
 *
 * POR QUÉ EXISTE: los cinco lugares que sirven archivos hacían
 * `if (! is_file($ruta)) throw new NotFoundHttpException('El archivo físico
 * no se encuentra en el repositorio.')`. El problema es que is_file()
 * devuelve false en DOS casos muy distintos:
 *
 *   1. El archivo no está (se borró, la ruta quedó mal).
 *   2. El archivo SÍ está, pero el usuario del proceso PHP no tiene permiso
 *      para verlo.
 *
 * El caso 2 es real y pasó: las carpetas de /var/repositorio/proveedores
 * quedaron con permisos 700 de otro usuario, así que TODOS los documentos
 * eran ilegibles para la app, y el portal respondía "el archivo no se
 * encuentra en el repositorio". Ese mensaje manda a buscar el archivo (que
 * está) en vez de mirar los permisos, y ahí se van horas.
 *
 * Ahora un problema de permisos:
 *   - devuelve 503 y no 404, porque es un problema del servidor y no un
 *     recurso inexistente;
 *   - dice al usuario que es un problema técnico, sin exponer rutas;
 *   - y deja en el log lo que hace falta para arreglarlo: la ruta, el usuario
 *     del proceso y el dueño de la carpeta.
 */
trait VerificaArchivoFisico
{
    /**
     * Lanza la excepción adecuada si el archivo no se puede entregar.
     * Si todo está bien, no hace nada.
     */
    protected function verificarArchivoEntregable(string $rutaCompleta): void
    {
        if (is_readable($rutaCompleta) && is_file($rutaCompleta)) {
            return;
        }

        // file_exists() usa las mismas reglas de permisos que is_file() para
        // el archivo, pero lo que suele fallar es el permiso de EJECUCIÓN
        // (recorrido) de alguna carpeta del camino. Se sube por el árbol
        // hasta encontrar el primer directorio que no se puede recorrer: ese
        // es el que hay que corregir.
        $carpetaCulpable = $this->primerDirectorioNoAccesible($rutaCompleta);

        if ($carpetaCulpable !== null) {
            Log::error('Archivo del repositorio ilegible por permisos', [
                'ruta' => $rutaCompleta,
                'directorio_sin_acceso' => $carpetaCulpable,
                'dueno_directorio' => $this->duenoDe($carpetaCulpable),
                'permisos_directorio' => $this->permisosDe($carpetaCulpable),
                'usuario_del_proceso' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'desconocido')
                    : get_current_user(),
                'como_arreglarlo' => 'El usuario que corre PHP necesita permiso de lectura y recorrido sobre esa carpeta.',
            ]);

            throw new ServiceUnavailableHttpException(
                null,
                'No podemos entregar el archivo por un problema de configuración del servidor. '
                .'Ya quedó registrado; avisa a Sistemas.'
            );
        }

        throw new NotFoundHttpException('El archivo ya no está en el repositorio.');
    }

    /**
     * Primer directorio del camino que el proceso no puede recorrer, o null
     * si todos son accesibles (o sea: el archivo realmente no está).
     */
    private function primerDirectorioNoAccesible(string $rutaCompleta): ?string
    {
        $partes = explode('/', ltrim(dirname($rutaCompleta), '/'));
        $acumulado = '';

        foreach ($partes as $parte) {
            $acumulado .= '/'.$parte;

            if (! is_dir($acumulado)) {
                // El directorio no existe: no es un problema de permisos.
                return null;
            }

            // is_executable sobre un directorio = permiso de recorrerlo.
            if (! is_readable($acumulado) || ! is_executable($acumulado)) {
                return $acumulado;
            }
        }

        return null;
    }

    private function duenoDe(string $ruta): string
    {
        if (! function_exists('posix_getpwuid')) {
            return 'desconocido';
        }

        $uid = @fileowner($ruta);

        return $uid === false ? 'desconocido' : (posix_getpwuid($uid)['name'] ?? (string) $uid);
    }

    private function permisosDe(string $ruta): string
    {
        $permisos = @fileperms($ruta);

        return $permisos === false ? 'desconocidos' : substr(sprintf('%o', $permisos), -4);
    }
}
