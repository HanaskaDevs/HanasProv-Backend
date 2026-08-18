<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Ficha_Productos\Mail\SolicitudCambioPrecioMail;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\SolicitudCambioPrecio;
use App\Modules\Ficha_Productos\Notifications\SolicitudCambioPrecioNotification;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bloqueo + aprobación del precio de un producto: un proveedor ya
 * APROBADO pide cambiar el precio de uno de sus productos -> el precio
 * queda bloqueado (Producto.Precio_En_Revision = 1, el resto del
 * producto sigue disponible con normalidad) y se notifica por correo a
 * los usuarios Admin y Calidad de la empresa. Si aprueban, el precio
 * nuevo se escribe recién ahí en Producto.Precio. Si rechazan, el
 * precio de Producto nunca se tocó, así que no hace falta "revertir"
 * nada, solo cerrar la solicitud y desbloquear.
 */
class SolicitudCambioPrecioService
{
    public function solicitar(Usuario $usuario, int $idEmpresaActiva, int $idProducto, float $precioNuevo): SolicitudCambioPrecio
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo un usuario proveedor puede solicitar un cambio de precio.');
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario no tiene un Proveedor asociado a la empresa activa.');
        }

        if ((int) $proveedor->Id_Estado_Proveedor !== EstadoProveedor::APROBADO) {
            throw new AccessDeniedHttpException('Solo un proveedor ya aprobado puede solicitar cambios de precio.');
        }

        $producto = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->findOrFail($idProducto);

        if ($producto->Precio_En_Revision) {
            throw ValidationException::withMessages([
                'precio' => ['Este producto ya tiene un cambio de precio pendiente de aprobación.'],
            ]);
        }

        $solicitud = DB::transaction(function () use ($usuario, $producto, $precioNuevo) {
            $nuevaSolicitud = SolicitudCambioPrecio::create([
                'Id_Producto' => $producto->Id_Producto,
                'Precio_Anterior' => $producto->Precio,
                'Precio_Nuevo' => $precioNuevo,
                'Estado' => 'Pendiente',
                'Solicitado_Por' => $usuario->Id_Usuario,
                'Fecha_Solicitud' => now(),
            ]);

            $producto->forceFill(['Precio_En_Revision' => true])->save();

            return $nuevaSolicitud;
        });

        $this->notificarAdminsYCalidad($proveedor, $producto, $solicitud);

        return $solicitud;
    }

    public function listarPendientes(Usuario $admin, int $idEmpresaActiva)
    {
        $this->verificarEsAdminOCalidad($admin, $idEmpresaActiva);

        return SolicitudCambioPrecio::whereHas(
            'producto.proveedor',
            fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)
        )
            ->where('Estado', 'Pendiente')
            ->with(['producto.proveedor', 'solicitante'])
            ->orderBy('Fecha_Solicitud')
            ->get();
    }

    public function aprobar(Usuario $admin, int $idEmpresaActiva, int $idSolicitud): SolicitudCambioPrecio
    {
        $solicitud = $this->solicitudDeLaEmpresa($admin, $idEmpresaActiva, $idSolicitud);

        DB::transaction(function () use ($admin, $solicitud) {
            $solicitud->producto->forceFill([
                'Precio' => $solicitud->Precio_Nuevo,
                'Precio_En_Revision' => false,
            ])->save();

            $solicitud->forceFill([
                'Estado' => 'Aprobado',
                'Resuelto_Por' => $admin->Id_Usuario,
                'Fecha_Resolucion' => now(),
            ])->save();
        });

        return $solicitud->fresh(['producto']);
    }

    public function rechazar(Usuario $admin, int $idEmpresaActiva, int $idSolicitud, ?string $motivo): SolicitudCambioPrecio
    {
        $solicitud = $this->solicitudDeLaEmpresa($admin, $idEmpresaActiva, $idSolicitud);

        DB::transaction(function () use ($admin, $solicitud, $motivo) {
            // El precio de Producto nunca se tocó al solicitar, así que
            // "volver al original" es simplemente desbloquearlo.
            $solicitud->producto->forceFill(['Precio_En_Revision' => false])->save();

            $solicitud->forceFill([
                'Estado' => 'Rechazado',
                'Resuelto_Por' => $admin->Id_Usuario,
                'Fecha_Resolucion' => now(),
                'Comentario_Resolucion' => $motivo,
            ])->save();
        });

        return $solicitud->fresh(['producto']);
    }

    protected function solicitudDeLaEmpresa(Usuario $admin, int $idEmpresaActiva, int $idSolicitud): SolicitudCambioPrecio
    {
        $this->verificarEsAdminOCalidad($admin, $idEmpresaActiva);

        $solicitud = SolicitudCambioPrecio::whereHas(
            'producto.proveedor',
            fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)
        )
            ->with('producto')
            ->findOrFail($idSolicitud);

        if ($solicitud->Estado !== 'Pendiente') {
            throw ValidationException::withMessages([
                'solicitud' => ['Esta solicitud ya fue resuelta.'],
            ]);
        }

        return $solicitud;
    }

    /**
     * Mismo criterio que CalificacionProveedorService::verificarEsAdmin,
     * pero acá también entra Calidad -> quien aprueba/rechaza un cambio
     * de precio puede ser cualquiera de los dos roles (o Sistemas).
     */
    protected function verificarEsAdminOCalidad(Usuario $usuario, int $idEmpresaActiva): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos pueden aprobar o rechazar cambios de precio.');
        }

        if (! $usuario->esAdmin($idEmpresaActiva) && ! $usuario->esCalidad($idEmpresaActiva) && ! $usuario->tieneRolEnEmpresa($idEmpresaActiva, 'Sistemas')) {
            throw new AccessDeniedHttpException('No tiene permiso para aprobar o rechazar cambios de precio.');
        }
    }

    protected function notificarAdminsYCalidad(Proveedor $proveedor, Producto $producto, SolicitudCambioPrecio $solicitud): void
    {
        $usuarios = Usuario::whereHas('usuarioEmpresas', function ($q) use ($proveedor) {
            $q->where('Id_Empresa', $proveedor->Id_Empresa)
                ->where('Activo', true)
                ->whereHas('rol', fn ($r) => $r->whereIn('Nombre_Rol', ['Admin', 'Calidad']));
        })
            ->where('Activo', 1)
            ->get();

        if ($usuarios->isEmpty()) {
            return;
        }

        Notification::send(
            $usuarios,
            new SolicitudCambioPrecioNotification($solicitud, $producto->Nombre_Producto, $proveedor->Razon_Social)
        );
    }
}
