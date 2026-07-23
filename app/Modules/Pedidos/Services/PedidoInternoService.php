<?php

namespace App\Modules\Pedidos\Services;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vista interna (Admin/Sistemas) de pedidos agrupados por bodega (Cod_Almacen).
 *
 * A diferencia de PedidoService (que lee Pedido_Compra/Detalle_Pedido_Compra,
 * las tablas de negocio ya sincronizadas para el proveedor), este servicio
 * consulta DIRECTO las tablas de staging BC_Det_Pedido_Compra / BC_Cab_Pedido_Compra
 * / BC_Ficha_Proveedor, por decisión explícita de negocio: la bodega es un
 * dato que solo existe ahí, y no queremos duplicar/sincronizar otra vez.
 *
 * Regla de validez: un Nro_Pedido solo se incluye si TODAS sus líneas en
 * BC_Det_Pedido_Compra tienen Cod_Almacen con valor. Si una sola línea no
 * tiene bodega, el pedido completo se descarta de esta vista (se evalúa
 * siempre sobre el pedido completo, sin importar los filtros de proveedor/
 * producto que aplique el usuario).
 *
 * Solo se muestran las 3 bodegas conocidas (CD-0001/CD-0002/CD-0003) —
 * cualquier pedido con un Cod_Almacen distinto queda fuera de esta vista
 * por completo, no se muestra en ningún lado.
 *
 * Filtro de producto: si el usuario filtra por producto, el pedido igual
 * calza como bloque, pero solo se listan las línea(s) que hicieron match,
 * no todas las líneas del pedido.
 *
 * Recepciones (BC_Pedido_Compra_Fill_Rate): esa tabla se actualiza cada 30
 * min (proceso externo) y entrega la cantidad ACUMULADA recibida hasta ese
 * momento por línea -> NUNCA se suma, siempre se toma el registro más
 * reciente (mismo criterio que ActualizarCantidadesRecibidasCommand, que
 * hace este mismo cruce para el proveedor). El cruce es por
 * (Empresa, Nro_Documento = Nro_Pedido, Nro_Linea) — esa es la llave única
 * real de la tabla, no el código de producto.
 *
 * % de entrega:
 *  - Por línea: cantidad_recibida / cantidad_pedida, tope 100%.
 *  - Por pedido: PONDERADO por cantidad -> suma de recibido / suma de
 *    pedido de TODAS sus líneas (no el promedio simple de los %).
 *  - Por bodega: promedio simple del % de cada uno de sus pedidos.
 */
class PedidoInternoService
{
    /** Únicas 3 bodegas válidas para esta vista. */
    protected const BODEGAS = ['CD-0001', 'CD-0002', 'CD-0003'];

    public function listarPorBodega(Usuario $usuario, int $idEmpresaActiva, array $filtros): array
    {
        $bodegasPermitidas = $this->obtenerBodegasPermitidas($usuario, $idEmpresaActiva);

        $empresa = Empresa::findOrFail($idEmpresaActiva);

        if (! $empresa->Empresa_BC) {
            throw new \RuntimeException('Esta empresa no tiene configurado el código Empresa_BC.');
        }

        $empresaBc = trim($empresa->Empresa_BC);

        $query = DB::table('BC_Det_Pedido_Compra as d')
            ->join('BC_Cab_Pedido_Compra as c', function ($join) {
                $join->on('c.Nro_Pedido', '=', 'd.Nro_Pedido')
                    ->on('c.Empresa', '=', 'd.Empresa')
                    ->on('c.Tipo_Documento', '=', 'd.Tipo_Documento');
            })
            ->leftJoin('BC_Ficha_Proveedor as p', function ($join) use ($empresaBc) {
                $join->on('p.Nro_Proveedor', '=', 'c.Nro_Proveedor')
                    ->where('p.Empresa', $empresaBc);
            })
            ->where('d.Empresa', $empresaBc)
            // Solo pedidos donde NINGUNA línea (de TODO el pedido, sin filtros) le falte bodega.
            ->whereNotExists(function ($sub) use ($empresaBc) {
                $sub->select(DB::raw(1))
                    ->from('BC_Det_Pedido_Compra as d2')
                    ->whereColumn('d2.Nro_Pedido', 'd.Nro_Pedido')
                    ->where('d2.Empresa', $empresaBc)
                    ->where(function ($q) {
                        $q->whereNull('d2.Cod_Almacen')
                            ->orWhere(DB::raw('LTRIM(RTRIM(d2.Cod_Almacen))'), '');
                    });
            });

        if (! empty($filtros['fecha_desde'])) {
            $query->whereRaw('CONVERT(date, c.Fecha_Registro_BC) >= CONVERT(date, ?, 120)', [$filtros['fecha_desde']]);
        }

        if (! empty($filtros['fecha_hasta'])) {
            $query->whereRaw('CONVERT(date, c.Fecha_Registro_BC) <= CONVERT(date, ?, 120)', [$filtros['fecha_hasta']]);
        }

        if (! empty($filtros['proveedor'])) {
            $texto = $filtros['proveedor'];
            $query->where(function ($q) use ($texto) {
                $q->where('p.Nombre', 'like', "%{$texto}%")
                    ->orWhere('p.Nro_Identificacion', 'like', "%{$texto}%");
            });
        }

        if (! empty($filtros['producto'])) {
            $texto = $filtros['producto'];
            $query->where(function ($q) use ($texto) {
                $q->where('d.Nro_Producto', 'like', "%{$texto}%")
                    ->orWhere('d.Descripcion', 'like', "%{$texto}%");
            });
        }

        // Solo las bodegas que este usuario puede ver -> cualquier otro
        // Cod_Almacen (o cualquier bodega no asignada a un Compras) queda
        // fuera de esta vista por completo.
        $query->whereIn(DB::raw('LTRIM(RTRIM(d.Cod_Almacen))'), $bodegasPermitidas);

        $filas = $query->select(
            'd.Nro_Pedido',
            'd.Nro_Linea',
            'd.Nro_Producto',
            'd.Descripcion',
            'd.Cod_Almacen',
            'd.Cantidad',
            'c.Fecha_Registro_BC',
            'c.Fecha_Recepcion_Esperada',
            'c.Estado_Pedido',
            'c.Nro_Proveedor',
            'p.Nombre as Nombre_Proveedor',
            'p.Nro_Identificacion as Ruc_Proveedor'
        )
            ->orderByDesc('c.Fecha_Registro_BC')
            ->orderBy('d.Nro_Linea')
            ->get();

        $nroPedidos = $filas->pluck('Nro_Pedido')->unique()->values()->all();
        $cantidadesRecibidas = $this->obtenerCantidadesRecibidas($empresaBc, $nroPedidos);

        return $this->agruparPorBodega($filas, $cantidadesRecibidas, $bodegasPermitidas);
    }

    /**
     * Trae, en una sola consulta, la cantidad recibida MÁS RECIENTE (por
     * Fecha_Registro) de BC_Pedido_Compra_Fill_Rate para cada combinación
     * (Nro_Documento, Nro_Linea) de los pedidos dados. Devuelve un mapa
     * "Nro_Pedido|Nro_Linea" => cantidad_recibida.
     */
    protected function obtenerCantidadesRecibidas(string $empresaBc, array $nroPedidos): array
    {
        if (empty($nroPedidos)) {
            return [];
        }

        $ultimoPorLinea = DB::table('BC_Pedido_Compra_Fill_Rate')
            ->select(
                'Nro_Documento',
                'Nro_Linea',
                'Cantidad_Recibida',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY Nro_Documento, Nro_Linea ORDER BY Fecha_Registro DESC) as rn')
            )
            ->where('Empresa', $empresaBc)
            ->whereIn('Nro_Documento', $nroPedidos);

        $filas = DB::query()
            ->fromSub($ultimoPorLinea, 't')
            ->where('rn', 1)
            ->get(['Nro_Documento', 'Nro_Linea', 'Cantidad_Recibida']);

        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[$fila->Nro_Documento . '|' . $fila->Nro_Linea] = (float) $fila->Cantidad_Recibida;
        }

        return $mapa;
    }

    protected function agruparPorBodega(Collection $filas, array $cantidadesRecibidas, array $bodegasPermitidas): array
    {
        $pedidos = $filas->groupBy('Nro_Pedido')->map(function (Collection $lineasPedido) use ($cantidadesRecibidas) {
            $primera = $lineasPedido->first();

            $totalPedido = 0.0;
            $totalRecibido = 0.0;

            $lineas = $lineasPedido->map(function ($l) use ($cantidadesRecibidas, &$totalPedido, &$totalRecibido) {
                $cantidad = (float) $l->Cantidad;
                $cantidadRecibida = $cantidadesRecibidas[$l->Nro_Pedido . '|' . $l->Nro_Linea] ?? 0.0;

                $totalPedido += $cantidad;
                $totalRecibido += $cantidadRecibida;

                $porcentajeLinea = $cantidad > 0
                    ? min(100, round(($cantidadRecibida / $cantidad) * 100))
                    : 0;

                return [
                    'nro_linea' => $l->Nro_Linea,
                    'nro_producto' => $l->Nro_Producto,
                    'descripcion' => $l->Descripcion,
                    'cantidad' => $cantidad,
                    'cantidad_recibida' => $cantidadRecibida,
                    'porcentaje_entrega' => $porcentajeLinea,
                ];
            })->values();

            // Ponderado por cantidad: suma recibido / suma pedido de TODAS las líneas.
            $porcentajePedido = $totalPedido > 0
                ? min(100, round(($totalRecibido / $totalPedido) * 100))
                : 0;

            return [
                'nro_pedido' => $primera->Nro_Pedido,
                'proveedor' => $primera->Nombre_Proveedor,
                'ruc_proveedor' => $primera->Ruc_Proveedor,
                'fecha_registro_bc' => $primera->Fecha_Registro_BC,
                'fecha_recepcion_esperada' => $primera->Fecha_Recepcion_Esperada,
                'estado_pedido_bc' => $primera->Estado_Pedido,
                'cod_almacen' => trim($primera->Cod_Almacen),
                'porcentaje_entrega' => $porcentajePedido,
                'lineas' => $lineas,
            ];
        })->values();

        $resultado = [];
        foreach ($bodegasPermitidas as $bodega) {
            $resultado[$bodega] = [
                'porcentaje_entrega' => 0,
                'pedidos' => [],
            ];
        }

        foreach ($pedidos as $pedido) {
            $resultado[$pedido['cod_almacen']]['pedidos'][] = $pedido;
        }

        // Por bodega: promedio simple del % de cada uno de sus pedidos.
        foreach ($bodegasPermitidas as $bodega) {
            $pedidosBodega = collect($resultado[$bodega]['pedidos']);
            $resultado[$bodega]['porcentaje_entrega'] = $pedidosBodega->isNotEmpty()
                ? round($pedidosBodega->avg('porcentaje_entrega'))
                : 0;
        }

        return $resultado;
    }

    /**
     * Admin/Sistemas ven las 3 bodegas siempre. Compras ve solo las que
     * tenga asignadas en Usuario_Bodega para esta empresa (puede ser 1, 2,
     * las 3, o ninguna todavía). Cualquier otro rol no tiene acceso.
     */
    protected function obtenerBodegasPermitidas(Usuario $usuario, int $idEmpresa): array
    {
        if ($usuario->esAdmin($idEmpresa) || $usuario->esSistemas($idEmpresa)) {
            return self::BODEGAS;
        }

        if ($usuario->esCompras($idEmpresa)) {
            return $usuario->codigosBodegasAsignadas($idEmpresa);
        }

        throw new AccessDeniedHttpException('No tiene permisos para ver los pedidos por bodega.');
    }

}