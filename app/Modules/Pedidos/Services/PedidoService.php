<?php

namespace App\Modules\Pedidos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


class PedidoService
{
    public function __construct(protected SincronizacionPedidosService $sincronizacion) {}

    /** Fecha con la que se clasifica: la esperada, o la de registro si no vino de BC. */
    protected const FECHA_EFECTIVA = 'COALESCE(Fecha_Recepcion_Esperada, Fecha_Registro_BC)';

    /**
     * Vista del PROVEEDOR sobre sus propios pedidos. Un pedido va a
     * Históricos si cumple CUALQUIERA de estas dos condiciones:
     *
     *   a) su fecha de recepción ya pasó, o
     *   b) está entregado al 100% (todas las líneas completas), aunque la
     *      fecha no haya llegado -> si ya se recibió todo, no hay nada que
     *      esperar.
     *
     * Vigentes es el complemento exacto: fecha de hoy en adelante Y todavía
     * no entregado del todo. Al ser complementos, ningún pedido puede salir
     * en las dos pestañas ni desaparecer de ambas.
     *
     * Cuando BC no manda Fecha_Recepcion_Esperada (es NULL-able) se usa
     * Fecha_Registro_BC, que es NOT NULL -> el COALESCE siempre resuelve.
     *
     * OJO: acá NO se filtra por el campo Estado. Un pedido cerrado a mano
     * sigue en Vigentes si su fecha no llegó y no está entregado completo.
     *
     * @param  string  $vista  'vigentes' | 'historicos'
     */
    public function listar(Usuario $usuario, int $idEmpresaActiva, string $vista): Collection
    {
        // Falla fuerte ante un valor inesperado. Antes esto era un else que
        // devolvía históricos en silencio, y hacía que las dos pestañas
        // mostraran exactamente lo mismo.
        if (! in_array($vista, ['vigentes', 'historicos'], true)) {
            throw new \InvalidArgumentException("Vista de pedidos no válida: {$vista}");
        }

        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        // Formato ISO explícito + CAST: las dos columnas son [date] y el
        // driver sqlsrv es ambiguo con DATEFORMAT dmy (ver el comentario de
        // App\Models\BaseModel). Sin esto, un 03/08 podría leerse como 8 de marzo.
        $hoy = now()->toDateString();

        $consulta = PedidoCompra::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->with('lineas');

        if ($vista === 'vigentes') {
            $consulta
                ->whereRaw(self::FECHA_EFECTIVA . ' >= CAST(? AS date)', [$hoy])
                ->where(fn ($q) => $this->noEntregadoCompleto($q))
                // Ascendente: lo que llega primero, arriba.
                ->orderByRaw(self::FECHA_EFECTIVA . ' ASC');
        } else {
            $consulta
                ->where(function ($q) use ($hoy) {
                    $q->whereRaw(self::FECHA_EFECTIVA . ' < CAST(? AS date)', [$hoy])
                        ->orWhere(fn ($sub) => $this->entregadoCompleto($sub));
                })
                // Descendente: lo más reciente, arriba.
                ->orderByRaw(self::FECHA_EFECTIVA . ' DESC');
        }

        return $consulta->get();
    }

    /**
     * Entregado al 100%: tiene líneas Y ninguna quedó corta.
     *
     * El requisito de "tiene líneas" no es adorno: sin él, un pedido sin
     * ninguna línea pasaría el whereDoesntHave y se contaría como entregado
     * completo. PedidoCompraResource calcula 0% en ese caso, así que hay que
     * tratarlo como NO entregado para que las dos vistas coincidan con la UI.
     */
    protected function entregadoCompleto($consulta): void
    {
        $consulta
            ->whereHas('lineas')
            ->whereDoesntHave('lineas', fn ($linea) => $linea->whereColumn('Cantidad_Recibida', '<', 'Cantidad'));
    }

    /** Complemento exacto de entregadoCompleto(): sin líneas, o con alguna incompleta. */
    protected function noEntregadoCompleto($consulta): void
    {
        $consulta
            ->whereDoesntHave('lineas')
            ->orWhereHas('lineas', fn ($linea) => $linea->whereColumn('Cantidad_Recibida', '<', 'Cantidad'));
    }

    /**
     * Botón "Actualizar pedidos": trae SOLO los pedidos de ESTE proveedor
     * en ESTA empresa — nunca el proceso completo de todos los proveedores.
     */
    public function actualizar(Usuario $usuario, int $idEmpresaActiva): int
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        return $this->sincronizacion->sincronizar($idEmpresaActiva, $proveedor->Ruc);
    }

    /**
     * Un usuario interno marca un pedido como Cerrado (ej. ya fue entregado).
     * Esto es 100% manejado en nuestra base, nunca depende de BC.
     */
    public function cerrar(int $idPedidoCompra, Usuario $ejecutor): PedidoCompra
    {
        $pedido = PedidoCompra::findOrFail($idPedidoCompra);

        $pedido->forceFill([
            'Estado' => 'Cerrado',
            'Cerrado_Por' => $ejecutor->Id_Usuario,
            'Fecha_Cierre' => now(),
        ])->save();

        return $pedido;
    }

    protected function miProveedor(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) consultan sus propios pedidos.');
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario no tiene un Proveedor asociado a la empresa activa.');
        }

        return $proveedor;
    }

    public function obtenerParaPdf(Usuario $usuario, int $idEmpresaActiva, array $ids): Collection
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        return PedidoCompra::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->whereIn('Id_Pedido_Compra', $ids)
            ->with('lineas')
            ->orderByDesc('Fecha_Registro_BC')
            ->get();
    }
}