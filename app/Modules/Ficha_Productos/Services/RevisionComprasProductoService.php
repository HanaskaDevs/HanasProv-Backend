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
 *
 * QUIÉN SE SIENTA EN ESTE ESCRITORIO (13-nov-2026, pedido del usuario):
 * depende del producto. Con código de Business Central lo revisa
 * CATÁLOGO; sin código -producto nuevo- lo sigue revisando COMPRAS (ver
 * Producto::revisorPrimerPaso). Solo la ficha del producto: la
 * documentación del proveedor no cambia. Admin, Sistemas y Calidad siguen
 * pudiendo resolver cualquiera, como antes.
 */
class RevisionComprasProductoService
{
    public function __construct(private AvisoProductosService $avisos)
    {
    }

    /**
     * Quién puede ENTRAR a la bandeja: Compras y Catálogo (cada uno con lo
     * suyo, ver pendientes()), más Admin, Sistemas y Calidad -Admin y
     * Sistemas por pedido del usuario ("admin pueden aprobar tanto en el
     * proceso de compras como en el de calidad"), Calidad con acceso total
     * desde el 16-oct-2026-.
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresaActiva): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos revisan los productos enviados.');
        }

        $puede = $usuario->esCompras($idEmpresaActiva)
            || $usuario->esCatalogo($idEmpresaActiva)
            || $this->resuelveTodo($usuario, $idEmpresaActiva);

        if (! $puede) {
            throw new AccessDeniedHttpException('Solo los roles Compras, Catálogo, Calidad, Admin y Sistemas revisan los productos enviados.');
        }
    }

    /** Admin, Sistemas y Calidad resuelven el primer paso de cualquier producto, con o sin código BC. */
    private function resuelveTodo(Usuario $usuario, int $idEmpresaActiva): bool
    {
        return $usuario->esAdmin($idEmpresaActiva)
            || $usuario->esSistemas($idEmpresaActiva)
            || $usuario->esCalidad($idEmpresaActiva);
    }

    /**
     * ¿Este usuario puede aprobar, devolver o retirar ESTE producto?
     * Compras solo los que no tienen código BC; Catálogo solo los que sí.
     */
    public function puedeResolver(Usuario $usuario, int $idEmpresaActiva, Producto $producto): bool
    {
        if ($this->resuelveTodo($usuario, $idEmpresaActiva)) {
            return true;
        }

        return $producto->tieneCodigoBc()
            ? $usuario->esCatalogo($idEmpresaActiva)
            : $usuario->esCompras($idEmpresaActiva);
    }

    private function verificarPuedeResolver(Usuario $usuario, int $idEmpresaActiva, Producto $producto): void
    {
        if (! $this->puedeResolver($usuario, $idEmpresaActiva, $producto)) {
            throw new AccessDeniedHttpException($producto->tieneCodigoBc()
                ? "\"{$producto->Nombre_Producto}\" tiene código de Business Central: lo revisa Catálogo."
                : "\"{$producto->Nombre_Producto}\" es un producto nuevo, sin código de Business Central: lo revisa Compras.");
        }
    }

    /**
     * Productos esperando el primer paso, de todos los proveedores de la
     * empresa activa. Es la bandeja de esta etapa.
     *
     * Cada uno ve lo suyo (13-nov-2026): Compras, los productos sin código
     * BC; Catálogo, los que tienen código; Admin, Sistemas y Calidad, todos.
     * Quien tenga Compras y Catálogo a la vez ve los dos.
     */
    public function pendientes(Usuario $usuario, int $idEmpresaActiva)
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $todos = $this->resuelveTodo($usuario, $idEmpresaActiva)
            || ($usuario->esCompras($idEmpresaActiva) && $usuario->esCatalogo($idEmpresaActiva));
        $conCodigo = fn ($q) => $q->whereNotNull('Bc_Nro_Producto')->whereRaw("LTRIM(RTRIM(Bc_Nro_Producto)) <> ''");
        $sinCodigo = fn ($q) => $q->whereNull('Bc_Nro_Producto')->orWhereRaw("LTRIM(RTRIM(Bc_Nro_Producto)) = ''");

        return Producto::query()
            ->when(! $todos && $usuario->esCatalogo($idEmpresaActiva), fn ($q) => $q->where($conCodigo))
            ->when(! $todos && ! $usuario->esCatalogo($idEmpresaActiva), fn ($q) => $q->where($sinCodigo))
            ->whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)->where('Activo', 1))
            ->where('Activo', 1)
            ->where('Etapa_Aprobacion', Producto::ETAPA_COMPRAS)
            ->where('Estado_Calificacion', 'Pendiente')
            ->with(['proveedor', 'unidadPresentacion', 'grupos', 'documentos.tipoDocumento', 'documentos.archivo'])
            ->orderBy('Fecha_Modificacion')
            ->orderBy('Id_Producto')
            ->get();
    }

    /** Compras (o Catálogo, si tiene código BC) lo aprueba -> pasa a la bandeja de Calidad. */
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

        // Todo o nada también con los permisos: si la selección trae
        // productos del otro escritorio (Compras vs Catálogo), no se aprueba
        // ninguno y se dice cuál.
        $ajenos = $productos->reject(fn (Producto $p) => $this->puedeResolver($usuario, $idEmpresaActiva, $p));

        if ($ajenos->isNotEmpty()) {
            throw new AccessDeniedHttpException(
                'No se aprobó ninguno: '.$ajenos->pluck('Nombre_Producto')->take(3)->implode(', ')
                .($ajenos->first()->tieneCodigoBc() ? ' lo revisa Catálogo (tiene código de Business Central).' : ' lo revisa Compras (producto nuevo).')
            );
        }

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
                'producto' => ['Este producto ya no está esperando esta revisión.'],
            ]);
        }

        $this->verificarPuedeResolver($usuario, $idEmpresaActiva, $producto);

        return $producto;
    }
}
