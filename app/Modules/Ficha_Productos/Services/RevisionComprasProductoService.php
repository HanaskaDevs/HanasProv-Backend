<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Ficha_Productos\Models\Producto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * PRIMER PASO del circuito de aprobación de productos (23-sep-2026).
 *
 *     proveedor envía  ->  COMPRAS  ->  Calidad  ->  aprobado
 *
 * Compras es quien mira primero: puede corregir los datos del producto
 * (eso ya lo hace desde "Productos por proveedor"), aprobarlo para que
 * siga a Calidad, rechazarlo para que el proveedor lo corrija, o
 * eliminarlo directamente.
 *
 * POR QUÉ RECHAZAR Y ELIMINAR SON DOS ACCIONES Y NO UNA (decisión del
 * usuario): rechazar devuelve el producto al proveedor con la observación
 * para que lo arregle y lo reenvíe -no se pierde el trabajo de cargar los
 * documentos-. Eliminar es para el producto que directamente no
 * corresponde al catálogo, y es definitivo. Las dos exigen observación:
 * un rechazo sin motivo deja al proveedor sin saber qué corregir, y es
 * exactamente el reclamo que originó este cambio.
 *
 * El aviso por correo nunca frena la acción: si el correo falla, la
 * decisión de Compras ya quedó guardada y se registra el error en el log
 * (mismo criterio que el posteo a BC).
 */
class RevisionComprasProductoService
{
    public function __construct(private AvisoProductosService $avisos)
    {
    }

    /**
     * Quién puede resolver esta etapa: Compras (los dueños del paso),
     * más Admin y Sistemas -pedido explícito del usuario: "admin pueden
     * aprobar tanto en el proceso de compras como en el de calidad", y
     * Sistemas tiene acceso total en todo el portal-. Calidad entra con
     * acceso total desde el 16-oct-2026 (pedido explícito del usuario).
     *
     * $soloVer: Catálogo (29-oct-2026) ve la bandeja -para saber qué entró y
     * corregirle los datos desde Productos por proveedor- pero no aprueba,
     * rechaza ni retira: esa decisión sigue siendo de Compras y Calidad.
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresaActiva, bool $soloVer = false): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos revisan los productos enviados.');
        }

        if ($soloVer && $usuario->esCatalogo($idEmpresaActiva)) {
            return;
        }

        $puede = $usuario->esCompras($idEmpresaActiva)
            || $usuario->esAdmin($idEmpresaActiva)
            || $usuario->esSistemas($idEmpresaActiva)
            || $usuario->esCalidad($idEmpresaActiva);

        if (! $puede) {
            throw new AccessDeniedHttpException('Solo los roles Compras, Calidad, Admin y Sistemas revisan los productos enviados.');
        }
    }

    /**
     * Productos esperando a Compras, de todos los proveedores de la
     * empresa activa. Es la bandeja de esta etapa.
     */
    public function pendientes(Usuario $usuario, int $idEmpresaActiva)
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva, soloVer: true);

        return Producto::query()
            ->whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)->where('Activo', 1))
            ->where('Activo', 1)
            ->where('Etapa_Aprobacion', Producto::ETAPA_COMPRAS)
            ->where('Estado_Calificacion', 'Pendiente')
            ->with(['proveedor', 'unidadPresentacion', 'grupos', 'documentos.tipoDocumento', 'documentos.archivo'])
            ->orderBy('Fecha_Modificacion')
            ->orderBy('Id_Producto')
            ->get();
    }

    /** Compras lo aprueba -> pasa a la bandeja de Calidad. */
    public function aprobar(Usuario $usuario, int $idEmpresaActiva, int $idProducto): Producto
    {
        $producto = $this->productoEnEtapaCompras($usuario, $idEmpresaActiva, $idProducto);

        $producto->forceFill([
            'Etapa_Aprobacion' => Producto::ETAPA_CALIDAD,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        $actualizado = $producto->fresh('proveedor');
        $this->avisos->avisarACalidad($actualizado->proveedor, collect([$actualizado]), $usuario);

        return $producto->fresh(['proveedor', 'unidadPresentacion', 'grupos']);
    }

    /**
     * Compras aprueba VARIOS de una vez (selección múltiple, 14-oct-2026).
     *
     * Todo o nada: si alguno ya no está esperando a Compras -otra persona
     * lo resolvió mientras tanto- no se aprueba ninguno y se dice cuál.
     * Aprobar "los que se pudieron" dejaría a quien seleccionó sin saber
     * qué pasó con el resto.
     *
     * Sale UN correo a Calidad por proveedor, no uno por producto: si la
     * selección mezcla proveedores, cada uno recibe su aviso con todo lo
     * suyo.
     *
     * @param  array<int, int>  $idsProductos
     * @return \Illuminate\Support\Collection<int, Producto>
     */
    public function aprobarVarios(Usuario $usuario, int $idEmpresaActiva, array $idsProductos)
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $ids = array_values(array_unique(array_map('intval', $idsProductos)));

        $productos = Producto::whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva))
            ->where('Activo', 1)
            ->whereIn('Id_Producto', $ids)
            ->with('proveedor')
            ->get();

        $noDisponibles = $productos->filter(
            fn (Producto $p) => $p->Etapa_Aprobacion !== Producto::ETAPA_COMPRAS || $p->Estado_Calificacion !== 'Pendiente'
        );

        if ($productos->count() !== count($ids) || $noDisponibles->isNotEmpty()) {
            $nombres = $noDisponibles->pluck('Nombre_Producto')->take(3)->implode(', ');

            throw ValidationException::withMessages([
                'productos' => [
                    $nombres !== ''
                        ? "No se aprobó ninguno: {$nombres} ya no está esperando la revisión de Compras. Actualiza la lista y vuelve a intentarlo."
                        : 'No se aprobó ninguno: algún producto seleccionado ya no está disponible. Actualiza la lista y vuelve a intentarlo.',
                ],
            ]);
        }

        DB::transaction(function () use ($productos, $usuario) {
            foreach ($productos as $producto) {
                $producto->forceFill([
                    'Etapa_Aprobacion' => Producto::ETAPA_CALIDAD,
                    'Modificado_Por' => $usuario->Id_Usuario,
                    'Fecha_Modificacion' => now(),
                ])->save();
            }
        });

        // Los avisos después de la transacción: si un correo falla, las
        // aprobaciones ya quedaron guardadas (ver AvisoProductosService).
        foreach ($productos->groupBy('Id_Proveedor') as $delProveedor) {
            $this->avisos->avisarACalidad($delProveedor->first()->proveedor, $delProveedor->values(), $usuario);
        }

        return $productos;
    }

    /**
     * Compras lo rechaza -> vuelve al proveedor para que lo corrija, con
     * la observación. El producto NO se borra: conserva sus documentos,
     * así el proveedor arregla lo que haga falta y lo reenvía.
     */
    public function rechazar(Usuario $usuario, int $idEmpresaActiva, int $idProducto, string $observacion): Producto
    {
        $producto = $this->productoEnEtapaCompras($usuario, $idEmpresaActiva, $idProducto);

        DB::transaction(function () use ($producto, $usuario, $observacion) {
            $producto->forceFill([
                'Estado_Calificacion' => 'Rechazado',
                // Se conserva la etapa donde se rechazó: es lo que permite
                // decirle al proveedor "te lo rechazó Compras" en vez de un
                // genérico que no le dice a quién preguntarle.
                'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
                'Comentario_Calificacion' => $observacion,
                'Calificado_Por' => $usuario->Id_Usuario,
                'Fecha_Calificacion' => now(),
            ])->save();

            // Deja el producto editable para el proveedor aunque siga
            // Bloqueado, igual que un rechazo de Calidad.
            $producto->proveedor->forceFill(['Correcciones_Pendientes_Productos' => true])->save();
        });

        $this->avisos->avisarAlProveedor($producto->fresh('proveedor'), $observacion, eliminado: false);

        return $producto->fresh(['proveedor', 'unidadPresentacion', 'grupos']);
    }

    /**
     * Compras lo elimina -> se va del catálogo, definitivo. Se avisa al
     * proveedor con el motivo ANTES de borrarlo, porque después del
     * borrado ya no hay de dónde sacar el nombre ni el proveedor.
     */
    public function eliminar(Usuario $usuario, int $idEmpresaActiva, int $idProducto, string $observacion): void
    {
        $producto = $this->productoEnEtapaCompras($usuario, $idEmpresaActiva, $idProducto);

        $this->avisos->avisarAlProveedor($producto, $observacion, eliminado: true);

        // Borrado lógico y no físico: el proveedor tiene que poder seguir
        // viendo en su historial que ese producto existió y por qué se
        // quitó, y los documentos que subió no se tiran a la basura.
        $producto->forceFill([
            'Activo' => 0,
            'Estado_Calificacion' => 'Rechazado',
            'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
            'Comentario_Calificacion' => $observacion,
            'Calificado_Por' => $usuario->Id_Usuario,
            'Fecha_Calificacion' => now(),
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        Log::info('Compras eliminó un producto enviado a aprobación.', [
            'id_producto' => $producto->Id_Producto,
            'id_usuario' => $usuario->Id_Usuario,
        ]);
    }

    /**
     * Resuelve el producto comprobando que de verdad esté esperando a
     * Compras. Sin esto se podría aprobar dos veces, o resolver desde
     * esta pantalla uno que ya está en Calidad.
     */
    private function productoEnEtapaCompras(Usuario $usuario, int $idEmpresaActiva, int $idProducto): Producto
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $producto = Producto::whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva))
            ->where('Activo', 1)
            ->with('proveedor')
            ->findOrFail($idProducto);

        if ($producto->Etapa_Aprobacion !== Producto::ETAPA_COMPRAS || $producto->Estado_Calificacion !== 'Pendiente') {
            throw ValidationException::withMessages([
                'producto' => ['Este producto ya no está esperando la revisión de Compras.'],
            ]);
        }

        return $producto;
    }
}
