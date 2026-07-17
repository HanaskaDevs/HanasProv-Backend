<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\CalificacionCampoFicha;
use App\Modules\Proveedores\Models\Proveedor;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Maneja el guardado progresivo de la Ficha de Proveedor (auto-guardado por
 * sección, reanudable). Solo el propio usuario externo (Tipo_Usuario =
 * Proveedor) puede leer/editar SU propia ficha -> nunca se recibe un
 * Id_Proveedor desde el cliente, siempre se resuelve desde el usuario
 * autenticado + la empresa activa de su sesión (un mismo usuario puede
 * estar vinculado a Proveedores de más de una empresa, vía Usuario_Proveedor).
 *
 * 3 secciones en total por ahora: Información del Proveedor (33%),
 * Clase de Proveedor (33%), Categoría de Productos/Servicios (34%).
 */
class FichaProveedorService
{
    public function obtenerMiFicha(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        return $this->miProveedor($usuario, $idEmpresaActiva)->load(['clases', 'categoriasProducto', 'estado', 'calificacionesCampos']);
    }

    public function guardarSeccion1(Usuario $usuario, int $idEmpresaActiva, array $data): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $proveedor->forceFill([
            'Ruc' => $data['ruc'],
            'Clase_Contribuyente' => $data['clase_contribuyente'] ?? null,
            'Razon_Social' => $data['razon_social'],
            'Nombre_Comercial' => $data['nombre_comercial'] ?? null,
            'Email' => $data['email'],
            'Telefono' => $data['telefono'] ?? null,
            'Direccion' => $data['direccion'] ?? null,
            'Ciudad' => $data['ciudad'] ?? null,
            'Pagina_Web' => $data['pagina_web'] ?? null,
            'Latitud' => $data['latitud'] ?? null,
            'Longitud' => $data['longitud'] ?? null,
            'Representante_Legal' => $data['representante_legal'] ?? null,
            'Correo_Representante' => $data['correo_representante'] ?? null,
            'Telefono_Representante' => $data['telefono_representante'] ?? null,
            'Contacto_Venta' => $data['contacto_venta'] ?? null,
            'Correo_Venta' => $data['correo_venta'] ?? null,
            'Telefono_Contacto_Venta' => $data['telefono_contacto_venta'] ?? null,
            'Contacto_Calidad' => $data['contacto_calidad'] ?? null,
            'Correo_Calidad' => $data['correo_calidad'] ?? null,
            'Telefono_Contacto_Calidad' => $data['telefono_contacto_calidad'] ?? null,
            'Contacto_Contabilidad' => $data['contacto_contabilidad'] ?? null,
            'Correo_Contabilidad' => $data['correo_contabilidad'] ?? null,
            'Telefono_Contabilidad' => $data['telefono_contabilidad'] ?? null,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        $this->recalcularProgreso($proveedor);
        $this->reabrirRevisionSiEstabaRechazada($proveedor, CalificacionProveedorService::CAMPOS_SECCION1);

        return $proveedor->fresh(['clases', 'categoriasProducto', 'calificacionesCampos']);
    }

    public function guardarSeccion2(Usuario $usuario, int $idEmpresaActiva, array $idClases): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $proveedor->clases()->sync($idClases);

        $proveedor->forceFill([
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        $this->recalcularProgreso($proveedor);
        $this->reabrirRevisionSiEstabaRechazada($proveedor, [CalificacionProveedorService::CAMPO_CLASE]);

        return $proveedor->fresh(['clases', 'categoriasProducto', 'calificacionesCampos']);
    }

    public function guardarSeccion3(Usuario $usuario, int $idEmpresaActiva, array $idCategorias): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $proveedor->categoriasProducto()->sync($idCategorias);

        $proveedor->forceFill([
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        $this->recalcularProgreso($proveedor);
        $this->reabrirRevisionSiEstabaRechazada($proveedor, [CalificacionProveedorService::CAMPO_CATEGORIA]);

        return $proveedor->fresh(['clases', 'categoriasProducto', 'calificacionesCampos']);
    }

    /**
     * Si alguno de los campos de ESTA sección estaba marcado "Rechazado"
     * por el admin, se borra esa calificación (vuelve a "Sin calificar")
     * -> como en la vista de corrección solo esos campos son editables,
     * el hecho de que se haya podido guardar esta sección implica que el
     * proveedor los tocó, así que corresponde que vuelvan a la cola de
     * revisión del admin. Los campos ya Aprobados de la misma sección NO
     * se tocan -> siguen aprobados (el proveedor no pudo haberlos
     * cambiado, estaban bloqueados en el formulario).
     */
    protected function reabrirRevisionSiEstabaRechazada(Proveedor $proveedor, array $camposDeEstaSeccion): void
    {
        CalificacionCampoFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->whereIn('Nombre_Campo', $camposDeEstaSeccion)
            ->where('Estado', 'Rechazado')
            ->delete();
    }

    /**
     * Resuelve el Proveedor del usuario autenticado QUE PERTENECE A LA
     * EMPRESA ACTIVA de su sesión. Un mismo usuario externo puede estar
     * vinculado (vía Usuario_Proveedor) a Proveedores de distintas
     * empresas -> nunca alcanza con tomar "el primero", hay que filtrar
     * por la empresa con la que está trabajando en este momento.
     */
    protected function miProveedor(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) tienen Ficha de Proveedor.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresaActiva)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene una Ficha de Proveedor asociada a la empresa activa.');
        }

        return $proveedor;
    }

    /**
     * Recalcula Seccion_Actual (próxima sección incompleta) y
     * Porcentaje_Completado_Ficha según los datos realmente guardados ->
     * es tolerante a que se llenen fuera de orden y a reanudar en
     * cualquier momento.
     *
     * Solo 3 secciones en total por ahora (Información, Clase, Categoría),
     * ponderadas 33% + 33% + 34% para sumar exactamente 100%.
     */
    protected function recalcularProgreso(Proveedor $proveedor): void
    {
        $proveedor->refresh();

        $seccion1Completa = filled($proveedor->Ruc) && filled($proveedor->Razon_Social);
        $seccion2Completa = $proveedor->clases()->exists();
        $seccion3Completa = $proveedor->categoriasProducto()->exists();

        $porcentaje = 0;
        $porcentaje += $seccion1Completa ? 33 : 0;
        $porcentaje += $seccion2Completa ? 33 : 0;
        $porcentaje += $seccion3Completa ? 34 : 0;

        $siguienteSeccion = match (true) {
            ! $seccion1Completa => 1,
            ! $seccion2Completa => 2,
            ! $seccion3Completa => 3,
            default => 3,
        };

        $proveedor->forceFill([
            'Porcentaje_Completado_Ficha' => $porcentaje,
            'Seccion_Actual' => $siguienteSeccion,
        ])->save();
    }
}