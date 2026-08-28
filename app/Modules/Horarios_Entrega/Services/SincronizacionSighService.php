<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Models\Empresa;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lee SIGH (servidor 192.168.1.135, conexión 'sqlsrv_sigh', SOLO LECTURA)
 * para saber en qué documentos de recepción quedó cada pedido, y así
 * disparar en automático los estados En_Recepcion/Recibido del calendario
 * de horarios de entrega (ver HorarioEntregaService).
 *
 * Cadena de joins, tal como la describió el usuario:
 *   SIGH.DocumentoInv.ItemPedido = SIGH.Documento.Item
 *     -> SIGH.Documento.NroDocumentoBC
 *     -> Portal_Proveedores.BC_Cab_Pedido_Compra.Nro_Pedido (mismo Nro_Proveedor)
 *     -> Portal_Proveedores.BC_Ficha_Proveedor.Nro_Proveedor = Nro_Identificacion
 *     -> Proveedor.Ruc (mismo Id_Empresa)
 *
 * SIGH y Portal_Proveedores son DOS SERVIDORES DISTINTOS (10.100.60.40 vs
 * 192.168.1.135), así que esta resolución se hace acá, en PHP, con 2
 * consultas de solo lectura (una por servidor) en vez de un JOIN SQL
 * directo entre ambos.
 */
class SincronizacionSighService
{
    /**
     * Para cada NroDocumentoBC dado, resuelve a qué Ruc de proveedor
     * corresponde (usando BC_Cab_Pedido_Compra + BC_Ficha_Proveedor, que
     * ya viven en Portal_Proveedores).
     *
     * @param  Collection<int, string>  $nrosDocumentoBc
     * @return array<string, string> NroDocumentoBC -> Ruc del proveedor
     */
    public function resolverRucsPorDocumentoBc(Collection $nrosDocumentoBc): array
    {
        if ($nrosDocumentoBc->isEmpty()) {
            return [];
        }

        $pedidos = DB::table('BC_Cab_Pedido_Compra')
            ->whereIn('Nro_Pedido', $nrosDocumentoBc->unique()->values())
            ->whereNotNull('Nro_Proveedor')
            ->get(['Nro_Pedido', 'Nro_Proveedor', 'Empresa']);

        if ($pedidos->isEmpty()) {
            return [];
        }

        $rucsPorProveedorBc = DB::table('BC_Ficha_Proveedor')
            ->whereIn('Nro_Proveedor', $pedidos->pluck('Nro_Proveedor')->unique()->values())
            ->pluck('Nro_Identificacion', 'Nro_Proveedor');

        $resultado = [];
        foreach ($pedidos as $pedido) {
            $ruc = $rucsPorProveedorBc[$pedido->Nro_Proveedor] ?? null;
            if ($ruc) {
                $resultado[$pedido->Nro_Pedido] = $ruc;
            }
        }

        return $resultado;
    }

    /**
     * Para los NroDocumentoBC que quedaron pendientes de recepción, trae
     * de SIGH.DocumentoInv el primer registro cuyo ItemPedido matchea el
     * Item del Documento con ese NroDocumentoBC -> ahí "ya entró en
     * recepción".
     *
     * @param  array<string>  $nrosDocumentoBc
     * @return array<string, true> NroDocumentoBC -> ya tiene registro en DocumentoInv
     */
    public function documentosConIngresoEnDocumentoInv(array $nrosDocumentoBc): array
    {
        if (empty($nrosDocumentoBc)) {
            return [];
        }

        try {
            $filas = DB::connection('sqlsrv_sigh')
                ->table('Documento as d')
                ->join('DocumentoInv as di', 'di.ItemPedido', '=', DB::raw('CAST(d.Item AS VARCHAR(10))'))
                ->whereIn('d.NroDocumentoBC', $nrosDocumentoBc)
                ->select('d.NroDocumentoBC')
                ->distinct()
                ->get();
        } catch (\Throwable $e) {
            Log::error('SincronizacionSighService: fallo al consultar Documento/DocumentoInv en SIGH.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $filas->pluck('NroDocumentoBC')->mapWithKeys(fn ($n) => [$n => true])->all();
    }

    /**
     * Para los NroDocumentoBC que ya están en recepción, revisa cuáles
     * llegaron a SIGH.Documento.EstadoPedido = 'N' -> "Recibido".
     *
     * @param  array<string>  $nrosDocumentoBc
     * @return array<string, true> NroDocumentoBC -> EstadoPedido = 'N'
     */
    public function documentosRecibidos(array $nrosDocumentoBc): array
    {
        if (empty($nrosDocumentoBc)) {
            return [];
        }

        try {
            $filas = DB::connection('sqlsrv_sigh')
                ->table('Documento')
                ->whereIn('NroDocumentoBC', $nrosDocumentoBc)
                ->where('EstadoPedido', 'N')
                ->select('NroDocumentoBC')
                ->distinct()
                ->get();
        } catch (\Throwable $e) {
            Log::error('SincronizacionSighService: fallo al consultar EstadoPedido en SIGH.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $filas->pluck('NroDocumentoBC')->mapWithKeys(fn ($n) => [$n => true])->all();
    }

    /**
     * Pedidos "candidatos" a ser el que llegó hoy. Antes traía TODO el
     * historial del proveedor (sin filtro de fecha) -> en pruebas con
     * Cornucopia (proveedor real, con 76 pedidos históricos) esto hizo
     * que el matching agarrara un pedido de hace semanas que por
     * casualidad ya tenía actividad en SIGH, en vez del pedido de hoy
     * (ver conversación con el usuario, 27-ago-2026).
     *
     * Primer intento (solo límite hacia atrás, sin límite hacia
     * adelante) TAMBIÉN falló en pruebas: agarró un pedido esperado para
     * una semana después (ya registrado en SIGH de antemano, con
     * actividad previa a su fecha real de entrega) porque el
     * orderByDesc lo ponía primero en la lista al ser "la fecha más
     * reciente". Por eso ahora la ventana tiene los DOS límites: no más
     * de 7 días atrás, y no más allá de HOY -> nunca un pedido del
     * futuro, ni uno de hace más de una semana.
     *
     * Los pedidos sin Fecha_Recepcion_Esperada todavía se dejan pasar
     * (no se puede descartar solo por no tener fecha), pero quedan al
     * final del orden porque orderByDesc pone los NULL de últimos.
     *
     * @return array<string>
     */
    public function documentosBcDelProveedor(int $idEmpresa, int $idProveedor): array
    {
        $empresa = Empresa::find($idEmpresa);

        if (! $empresa || ! $empresa->Empresa_BC) {
            return [];
        }

        $ruc = DB::table('Proveedor')->where('Id_Proveedor', $idProveedor)->value('Ruc');

        if (! $ruc) {
            return [];
        }

        $nroProveedorBc = DB::table('BC_Ficha_Proveedor')
            ->where('Empresa', trim($empresa->Empresa_BC))
            ->where('Nro_Identificacion', $ruc)
            ->value('Nro_Proveedor');

        if (! $nroProveedorBc) {
            return [];
        }

        return DB::table('BC_Cab_Pedido_Compra')
            ->where('Empresa', trim($empresa->Empresa_BC))
            ->where('Nro_Proveedor', $nroProveedorBc)
            ->where(function ($query) {
                $query->whereNull('Fecha_Recepcion_Esperada')
                    ->orWhereBetween('Fecha_Recepcion_Esperada', [
                        now()->subDays(7)->startOfDay(),
                        now()->endOfDay(),
                    ]);
            })
            ->orderByDesc('Fecha_Recepcion_Esperada')
            ->pluck('Nro_Pedido')
            ->all();
    }
}
