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

    public function listar(Usuario $usuario, int $idEmpresaActiva, string $estado): Collection
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        return PedidoCompra::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Estado', $estado)
            ->where('Activo', 1)
            ->with('lineas')
            ->orderByDesc('Fecha_Registro_BC')
            ->get();
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
