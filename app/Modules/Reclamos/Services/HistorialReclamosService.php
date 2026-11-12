<?php

namespace App\Modules\Reclamos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Reclamos\Models\Reclamo;
use App\Modules\Reclamos\Models\ReclamoMensaje;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Reporte "Historial de reclamos" (12-nov-2026, pedido del usuario).
 *
 * Las pantallas de Reclamos abiertos / cerrados sirven para TRABAJAR el
 * reclamo del día. Esto es otra cosa: ver, por proveedor, todo lo que se
 * le reclamó alguna vez, y poder bajarse la conversación completa -con
 * las fotos- para mandarla por fuera del portal o archivarla.
 *
 * SOLO LECTURA: desde acá no se responde ni se cierra nada; para eso
 * están las pantallas de siempre.
 */
class HistorialReclamosService
{
    /**
     * Calidad, Admin y Sistemas (pedido explícito del usuario). Compras ve
     * los reclamos en sus pantallas de Operación, pero no este reporte.
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->Tipo_Usuario === 'Interno'
            && ($usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa) || $usuario->esCalidad($idEmpresa));

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el historial de reclamos.');
        }
    }

    /**
     * Un renglón por proveedor CON reclamos, para la tabla del reporte.
     *
     * Los proveedores sin ningún reclamo no aparecen: el reporte es el
     * historial, y una lista llena de ceros solo haría buscar más.
     */
    public function proveedoresConReclamos(Usuario $usuario, int $idEmpresa): Collection
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        $reclamos = Reclamo::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->with('proveedor')
            ->get();

        return $reclamos
            ->groupBy('Id_Proveedor')
            ->map(function (EloquentCollection $delProveedor) {
                $proveedor = $delProveedor->first()->proveedor;

                return [
                    'id_proveedor' => $proveedor?->Id_Proveedor,
                    'razon_social' => $proveedor?->Razon_Social,
                    'nombre_comercial' => $proveedor?->Nombre_Comercial,
                    'ruc' => $proveedor?->Ruc,
                    'total' => $delProveedor->count(),
                    'abiertos' => $delProveedor->where('Estado', 'Abierto')->count(),
                    'cerrados' => $delProveedor->where('Estado', '!=', 'Abierto')->count(),
                    'ultimo_reclamo' => $delProveedor->max('Fecha_Creacion')?->format('Y-m-d'),
                ];
            })
            ->filter(fn (array $fila) => $fila['id_proveedor'] !== null)
            ->sortByDesc('ultimo_reclamo')
            ->values();
    }

    /**
     * Todo el historial de UN proveedor: cada reclamo con su conversación
     * completa y las fotos de cada mensaje.
     */
    public function historialDeProveedor(Usuario $usuario, int $idEmpresa, int $idProveedor): array
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)->findOrFail($idProveedor);
        $reclamos = $this->reclamosDelProveedor($idEmpresa, $idProveedor);

        return [
            'proveedor' => [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'razon_social' => $proveedor->Razon_Social,
                'nombre_comercial' => $proveedor->Nombre_Comercial,
                'ruc' => $proveedor->Ruc,
            ],
            'reclamos' => $reclamos->map(fn (Reclamo $r) => $this->serializarReclamo($r))->values(),
        ];
    }

    /** Un reclamo concreto, comprobando que sea de la empresa activa. */
    public function reclamoDeLaEmpresa(Usuario $usuario, int $idEmpresa, int $idReclamo): Reclamo
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        return Reclamo::where('Id_Empresa', $idEmpresa)
            ->with(['proveedor', 'creadoPor', 'destinatarios', 'mensajes.autor', 'mensajes.imagenes.archivo'])
            ->findOrFail($idReclamo);
    }

    /**
     * @return EloquentCollection<int, Reclamo>
     */
    public function reclamosDelProveedor(int $idEmpresa, int $idProveedor): EloquentCollection
    {
        return Reclamo::where('Id_Empresa', $idEmpresa)
            ->where('Id_Proveedor', $idProveedor)
            ->where('Activo', 1)
            ->with(['proveedor', 'creadoPor', 'destinatarios', 'mensajes.autor', 'mensajes.imagenes.archivo'])
            ->orderByDesc('Fecha_Creacion')
            ->get();
    }

    /**
     * Los datos de un reclamo tal como los dibuja el PDF.
     *
     * LAS FOTOS VAN EMBEBIDAS EN BASE64 y no como ruta de archivo: dompdf
     * resuelve las rutas locales contra su propio chroot y, en el
     * servidor, el repositorio está fuera del proyecto
     * (/var/repositorio/...). Embebidas, el PDF se arma igual en local y
     * en producción, y además queda completo si alguien lo reenvía.
     */
    public function datosParaPdf(Reclamo $reclamo): array
    {
        return [
            'id' => $reclamo->Id_Reclamo,
            'asunto' => $reclamo->Asunto,
            'tipo' => $reclamo->Tipo_Reclamo,
            'impacto' => $reclamo->Impacto_Proveedor,
            'estado' => $reclamo->Estado,
            'creado_por' => $reclamo->creadoPor?->Nombre_Completo,
            'fecha_creacion' => $reclamo->Fecha_Creacion?->format('d/m/Y H:i'),
            'fecha_cierre' => $reclamo->Fecha_Cierre?->format('d/m/Y H:i'),
            'destinatarios' => $reclamo->destinatarios
                ->map(fn ($d) => trim(($d->Nombre_Contacto ?? $d->Rol_Contacto) . ' <' . $d->Email . '>'))
                ->values()
                ->all(),
            'mensajes' => $reclamo->mensajes->map(fn (ReclamoMensaje $m) => [
                'autor' => $m->autor?->Nombre_Completo ?? 'Usuario',
                'fecha' => $m->Fecha_Creacion?->format('d/m/Y H:i'),
                'texto' => $m->Mensaje,
                'imagenes' => $m->imagenes
                    ->map(fn ($i) => $this->imagenEmbebida($i->archivo?->Ruta_Almacenamiento, $i->archivo?->Tipo_Mime))
                    ->filter()
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    /**
     * data:URI de una imagen del repositorio, o null si el archivo ya no
     * está (un PDF al que le falte una foto es mejor que un PDF que no se
     * puede generar).
     */
    protected function imagenEmbebida(?string $ruta, ?string $mime): ?string
    {
        if (! $ruta) {
            return null;
        }

        $disco = Storage::disk('reclamos');

        if (! $disco->exists($ruta)) {
            return null;
        }

        $contenido = $disco->get($ruta);

        if ($contenido === null) {
            return null;
        }

        return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($contenido);
    }

    protected function serializarReclamo(Reclamo $reclamo): array
    {
        return [
            'id_reclamo' => $reclamo->Id_Reclamo,
            'asunto' => $reclamo->Asunto,
            'tipo_reclamo' => $reclamo->Tipo_Reclamo,
            'impacto_proveedor' => $reclamo->Impacto_Proveedor,
            'estado' => $reclamo->Estado,
            'creado_por' => $reclamo->creadoPor?->Nombre_Completo,
            'fecha_creacion' => $reclamo->Fecha_Creacion?->format('Y-m-d H:i'),
            'fecha_cierre' => $reclamo->Fecha_Cierre?->format('Y-m-d H:i'),
            'total_mensajes' => $reclamo->mensajes->count(),
            'total_imagenes' => $reclamo->mensajes->sum(fn (ReclamoMensaje $m) => $m->imagenes->count()),
            'mensajes' => $reclamo->mensajes->map(fn (ReclamoMensaje $m) => [
                'id_reclamo_mensaje' => $m->Id_Reclamo_Mensaje,
                'autor' => $m->autor?->Nombre_Completo ?? 'Usuario',
                'fecha' => $m->Fecha_Creacion?->format('Y-m-d H:i'),
                'mensaje' => $m->Mensaje,
                'imagenes' => $m->imagenes->map(fn ($i) => [
                    'id' => $i->Id_Reclamo_Mensaje_Imagen,
                    'nombre' => $i->archivo?->Nombre_Original,
                    // Mismo endpoint que ya usa la pantalla de reclamos:
                    // no hace falta uno nuevo solo para el reporte.
                    'url' => '/api/reclamos/imagenes/' . $i->Id_Reclamo_Mensaje_Imagen . '/ver',
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
