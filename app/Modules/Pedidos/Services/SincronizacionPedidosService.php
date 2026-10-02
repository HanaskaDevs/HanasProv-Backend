<?php

namespace App\Modules\Pedidos\Services;

use App\Models\Empresa;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trae pedidos "Released" desde las tablas locales de staging BC_Cab_Pedido_Compra
 * / BC_Det_Pedido_Compra (alimentadas por un proceso externo, solo lectura para
 * este servicio) y los guarda en las tablas de negocio Pedido_Compra /
 * Detalle_Pedido_Compra. Nunca escribe nada en las tablas BC_*.
 *
 * Matching:
 *  - Empresa local <-> BC_Cab_Pedido_Compra.Empresa / BC_Det_Pedido_Compra.Empresa
 *    vía Empresa.Empresa_BC (el Nro_Pedido NO es único entre empresas, así que
 *    TODA consulta debe ir filtrada también por Empresa).
 *  - BC_Cab_Pedido_Compra.Nro_Proveedor -> BC_Ficha_Proveedor.Nro_Proveedor
 *  - BC_Ficha_Proveedor.Nro_Identificacion -> Proveedor.Ruc (local, misma empresa)
 *
 * Ventana móvil configurable sobre Fecha_Registro_BC (PEDIDOS_DIAS_VENTANA,
 * 7 días por defecto). Los pedidos que ya existen localmente (Abiertos o
 * Cerrados) se excluyen por completo, nunca se vuelven a tocar. Cada
 * pedido se guarda dentro de una transacción (cabecera + líneas juntas)
 * para que nunca quede a medias.
 *
 * LA VENTANA ES LA RED DE SEGURIDAD, no una optimización: es lo único que
 * permite recuperar un pedido si la sincronización no corrió. Con 4
 * corridas por día y 7 días de ventana, el portal se recupera solo de una
 * semana entera caída. Un pedido cuyo Fecha_Registro_BC quede fuera de la
 * ventana NO lo trae nadie, ni el cron ni el botón del proveedor.
 *
 * CÓMO SE EVITA QUE ESTO ESCALE MAL. Con la frecuencia alta, una corrida
 * puede encontrar cientos de pedidos, así que todo lo que antes se hacía
 * de a uno ahora se hace en bloque:
 *
 *  - Los pedidos ya importados se descartan con un NOT EXISTS contra
 *    Pedido_Compra, no mandando la lista de los que ya hay. Esa lista
 *    tenía 824 entradas y crecía ~20 por día: al pasar las 2100, SQL
 *    Server rechaza la consulta entera ("maximum of 2100 parameters") y
 *    la sincronización se cae en silencio para toda la empresa. Con el
 *    NOT EXISTS son cero parámetros y resuelve por el índice
 *    UQ_PedidoCompra_Empresa_Nro, que ya existe.
 *  - Los proveedores se cargan una vez y se buscan en memoria por RUC,
 *    en vez de una consulta por pedido.
 *  - Las líneas de todos los pedidos encontrados se traen en una sola
 *    consulta, en vez de una por pedido.
 *
 * Antes, una corrida de 400 pedidos hacía más de 800 consultas; ahora son
 * cuatro más las inserciones.
 *
 * IMPORTANTE: sincronizar() SIEMPRE requiere $rucFiltro cuando se llama
 * desde una petición HTTP (el botón "Actualizar pedidos" de un proveedor) —
 * solo el comando programado (SincronizarPedidosDiario) puede omitirlo para
 * traer todos los proveedores de una empresa de una sola vez.
 */
class SincronizacionPedidosService
{
    public function sincronizar(int $idEmpresa, ?string $rucFiltro = null): int
    {
        $empresa = Empresa::findOrFail($idEmpresa);

        if (! $empresa->Empresa_BC) {
            throw new \RuntimeException('Esta empresa no tiene configurado el código Empresa_BC.');
        }

        $empresaBc = trim($empresa->Empresa_BC);

        $diasVentana = max(1, (int) config('portal.pedidos.dias_ventana', 7));

        $fechaDesde = now()->subDays($diasVentana)->startOfDay()->format('Y-m-d\TH:i:s');
        $fechaHasta = now()->endOfDay()->format('Y-m-d\TH:i:s');

        $query = DB::table('BC_Cab_Pedido_Compra as c')
            ->join('BC_Ficha_Proveedor as p', 'p.Nro_Proveedor', '=', 'c.Nro_Proveedor')
            ->where('c.Empresa', $empresaBc)
            ->where('c.Estado_Pedido', 'Released')
            ->whereBetween('c.Fecha_Registro_BC', [$fechaDesde, $fechaHasta])
            ->select(
                'c.Nro_Pedido',
                'c.Fecha_Registro_BC',
                'c.Fecha_Recepcion_Esperada',
                'c.Estado_Pedido',
                'p.Nro_Identificacion'
            );

        // Los que ya están importados se descartan en la propia consulta.
        // Ver el comentario de la clase: mandar la lista como parámetros
        // revienta al pasar los 2100 pedidos locales.
        $query->whereNotExists(function ($sub) use ($idEmpresa) {
            $sub->selectRaw('1')
                ->from('Pedido_Compra as existente')
                ->where('existente.Id_Empresa', $idEmpresa)
                ->whereColumn('existente.Nro_Pedido', 'c.Nro_Pedido');
        });

        if ($rucFiltro) {
            $query->where('p.Nro_Identificacion', $rucFiltro);
        }

        // Antes acá se volcaba al log el SQL completo con sus bindings. Se
        // quitó: no aportaba nada en operación normal, engordaba el log
        // (6,8 MB sin rotar) y dejaba escrita la estructura interna de las
        // consultas, que es justo lo que quiere ver alguien preparando una
        // inyección. El conteo de resultados sí queda: es lo único que se
        // usaba de verdad para saber si la sincronización trajo algo.
        $pedidosBC = $query->get();

        Log::info('Sincronización de pedidos', [
            'id_empresa' => $idEmpresa,
            'encontrados_en_bc' => $pedidosBC->count(),
        ]);

        if ($pedidosBC->isEmpty()) {
            return 0;
        }

        // Una consulta por los proveedores de la empresa, indexados por RUC.
        // Antes era una consulta por cada pedido encontrado.
        $proveedoresPorRuc = Proveedor::where('Id_Empresa', $idEmpresa)
            ->get(['Id_Proveedor', 'Ruc'])
            ->keyBy(fn (Proveedor $proveedor) => trim((string) $proveedor->Ruc));

        // Solo los pedidos cuyo proveedor existe en el portal. Los demás no
        // tienen a quién mostrárselos, así que ni se les buscan las líneas.
        $pedidosBC = $pedidosBC->filter(
            fn ($pedidoBC) => $proveedoresPorRuc->has(trim((string) $pedidoBC->Nro_Identificacion))
        )->values();

        if ($pedidosBC->isEmpty()) {
            return 0;
        }

        $lineasPorPedido = $this->lineasDe($empresaBc, $pedidosBC->pluck('Nro_Pedido')->all());

        $totalSincronizados = 0;

        foreach ($pedidosBC as $pedidoBC) {
            $proveedor = $proveedoresPorRuc->get(trim((string) $pedidoBC->Nro_Identificacion));

            try {
                DB::transaction(function () use ($pedidoBC, $idEmpresa, $proveedor, $lineasPorPedido) {
                    $pedidoLocal = PedidoCompra::create([
                        'Id_Empresa' => $idEmpresa,
                        'Id_Proveedor' => $proveedor->Id_Proveedor,
                        'Nro_Pedido' => $pedidoBC->Nro_Pedido,
                        'Fecha_Registro_BC' => $pedidoBC->Fecha_Registro_BC,
                        'Fecha_Recepcion_Esperada' => $pedidoBC->Fecha_Recepcion_Esperada,
                        'Estado_Pedido_BC' => $pedidoBC->Estado_Pedido,
                        'Estado' => 'Abierto',
                        'Fecha_Sincronizacion' => now(),
                        'Activo' => 1,
                    ]);

                    $lineas = $lineasPorPedido->get($pedidoBC->Nro_Pedido, collect());

                    if ($lineas->isEmpty()) {
                        return;
                    }

                    /*
                     * Las líneas entran en bloque, de a 300. Una inserción
                     * múltiple manda 5 parámetros por fila y SQL Server
                     * admite 2100 por consulta: el pedido más grande de BC
                     * hoy tiene 185 líneas (925 parámetros), así que entra
                     * de una, pero el tope está puesto para que uno más
                     * grande no rompa la sincronización entera.
                     */
                    $lineas
                        ->map(fn ($linea) => [
                            'Id_Pedido_Compra' => $pedidoLocal->Id_Pedido_Compra,
                            'Nro_Linea' => $linea->Nro_Linea,
                            'Codigo_Producto' => $linea->Nro_Producto,
                            'Descripcion' => $linea->Descripcion,
                            'Cantidad' => $linea->Cantidad,
                        ])
                        ->chunk(300)
                        ->each(fn (Collection $tanda) => DetallePedidoCompra::insert($tanda->all()));
                });

                $totalSincronizados++;
            } catch (QueryException $e) {
                /*
                 * EL PEDIDO YA ESTABA. Pasa cuando el proveedor aprieta
                 * "Actualizar pedidos" justo mientras corre el cron: los dos
                 * leyeron la misma foto y los dos intentan insertarlo. El
                 * índice único UQ_PedidoCompra_Empresa_Nro lo rechaza, que
                 * es exactamente lo que queremos.
                 *
                 * Se saltea en vez de propagar: sin esto, la carrera
                 * abortaba la corrida entera y los pedidos que venían
                 * después quedaban sin traer.
                 */
                if (! $this->esPedidoDuplicado($e)) {
                    throw $e;
                }

                Log::info('Sincronización: el pedido ya había sido importado por otra corrida.', [
                    'nro_pedido' => $pedidoBC->Nro_Pedido,
                ]);
            }
        }

        return $totalSincronizados;
    }

    /**
     * Las líneas de TODOS los pedidos de la tanda, agrupadas por
     * Nro_Pedido. Una consulta en vez de una por pedido.
     *
     * Se trae de a 1000 números porque un whereIn viaja como parámetros y
     * SQL Server admite 2100 por consulta: una tanda grande después de
     * varios días sin sincronizar superaría el tope.
     *
     * @param  array<int, string>  $nroPedidos
     */
    protected function lineasDe(string $empresaBc, array $nroPedidos): Collection
    {
        return collect($nroPedidos)
            ->chunk(1000)
            ->flatMap(fn (Collection $tanda) => DB::table('BC_Det_Pedido_Compra')
                ->where('Empresa', $empresaBc)
                ->whereIn('Nro_Pedido', $tanda->all())
                ->select('Nro_Pedido', 'Nro_Linea', 'Nro_Producto', 'Descripcion', 'Cantidad')
                ->get()
            )
            ->groupBy('Nro_Pedido');
    }

    /** ¿El error es el índice único de Pedido_Compra y no otra cosa? */
    protected function esPedidoDuplicado(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'UQ_PedidoCompra_Empresa_Nro');
    }
}