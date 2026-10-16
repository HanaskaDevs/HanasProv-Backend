<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\HistorialEstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProveedorService
{
    /**
     * Listado con RUC, razón social y correo de TODOS los proveedores de la
     * empresa -> solo para quien los revisa (ver verificarPuedeVer).
     */
    public function listarPorEmpresa(Usuario $usuario, int $idEmpresa): Collection
    {
        $this->verificarPuedeVer($usuario, $idEmpresa);

        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', true)
            ->with(['estado', 'clases', 'calificacionesCampos'])
            ->withCount([
                // Documentos ya cargados (Activo) que todavía no tienen
                // Estado_Calificacion -> lo que el admin tiene pendiente de
                // revisar en la pestaña de Documentos.
                'documentos as documentos_pendientes_calificar_count' => function ($query) {
                    $query->where('Activo', 1)->whereNull('Estado_Calificacion');
                },
                'documentos as documentos_rechazados_count' => function ($query) {
                    $query->where('Activo', 1)->where('Estado_Calificacion', 'Rechazado');
                },
                'documentos as documentos_totales_count' => function ($query) {
                    $query->where('Activo', 1);
                },
            ])
            ->get();
    }

    /**
     * Detalle de un proveedor, siempre dentro de la empresa activa: antes
     * se resolvía por route model binding y con el id de un proveedor de
     * otra empresa del grupo alcanzaba para verlo.
     */
    public function obtener(Usuario $usuario, int $idEmpresa, int $idProveedor): Proveedor
    {
        $this->verificarPuedeVer($usuario, $idEmpresa);

        $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->find($idProveedor);

        if (! $proveedor) {
            throw new NotFoundHttpException('El proveedor no existe en la empresa activa.');
        }

        return $proveedor->load(['estado', 'clases', 'categoriasProducto']);
    }

    /**
     * Alta manual de un proveedor como Aspirante. Ninguna pantalla la usa
     * (los proveedores entran por la activación de su cuenta), así que
     * queda solo para quien administra: Sistemas y Admin.
     */
    public function crear(Usuario $usuario, int $idEmpresa, array $datos): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Interno'
            || ! ($usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa))) {
            throw new AccessDeniedHttpException('Solo los roles Sistemas y Admin pueden dar de alta proveedores.');
        }

        // El request valida en minúsculas y el modelo usa los nombres de
        // columna: con `...$datos` directo, Eloquent descartaba las claves
        // (no están en $fillable) y el proveedor quedaba sin RUC ni nombre.
        return Proveedor::create([
            'Ruc' => $datos['ruc'],
            'Razon_Social' => $datos['razon_social'],
            'Email' => $datos['email'],
            'Id_Empresa' => $idEmpresa,
            'Id_Estado_Proveedor' => EstadoProveedor::ASPIRANTE,
            'Seccion_Actual' => 1,
            'Porcentaje_Completado_Ficha' => 0,
            'Fecha_Postulacion' => now(),
            'Activo' => true,
        ]);
    }

    /**
     * Cambia el estado de un proveedor y deja constancia en el historial
     * dentro de una misma transacción.
     */
    public function cambiarEstado(Proveedor $proveedor, int $idEstadoNuevo, ?string $motivo, ?int $idUsuario): Proveedor
    {
        return DB::transaction(function () use ($proveedor, $idEstadoNuevo, $motivo, $idUsuario) {
            $idEstadoAnterior = $proveedor->Id_Estado_Proveedor;

            $proveedor->forceFill(['Id_Estado_Proveedor' => $idEstadoNuevo])->save();

            HistorialEstadoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Estado_Anterior' => $idEstadoAnterior,
                'Id_Estado_Nuevo' => $idEstadoNuevo,
                'Motivo' => $motivo,
                'Id_Usuario' => $idUsuario,
                'Fecha_Cambio' => now(),
            ]);

            return $proveedor->fresh();
        });
    }

    /**
     * Quién puede ver el listado y el detalle: Sistemas y Admin porque
     * administran, Calidad porque la calificación de proveedores es suya, y
     * Compras porque desde el 16-oct-2026 también revisa y califica la
     * ficha (ver CalificacionProveedorService::verificarPuedeCalificarFicha).
     * Guardia queda fuera, y el Proveedor sobre todo: era el que podía
     * bajarse a su competencia.
     */
    protected function verificarPuedeVer(Usuario $usuario, int $idEmpresa): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos pueden ver los proveedores de la empresa.');
        }

        $puede = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCalidad($idEmpresa)
            || $usuario->esCompras($idEmpresa);

        if (! $puede) {
            throw new AccessDeniedHttpException('Solo los roles Sistemas, Admin, Calidad y Compras pueden ver los proveedores de la empresa.');
        }
    }
}
