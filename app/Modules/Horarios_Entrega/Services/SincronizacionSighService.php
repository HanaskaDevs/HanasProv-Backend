<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Models\Empresa;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
 * SIGH y Portal_Proveedores son DOS SERVIDORES DISTINTOS (10.100.60.75 vs
 * 192.168.1.135), así que esta resolución se hace acá, en PHP, con
 * consultas de solo lectura (una por servidor) en vez de un JOIN SQL
 * directo entre ambos. WMS, en cambio, está en el MISMO servidor que SIGH
 * y se cruza en la misma consulta (ver recepcionesEnWms).
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
     * Base de WMS. Vive en el MISMO servidor que SIGH (SRVHANASTEKNEW), así
     * que se lee con la misma conexión 'sqlsrv_sigh' y nombres de tres
     * partes (WMS.dbo.tmpRecepcion). Verificado el 12-nov-2026.
     */
    public const BASE_WMS = 'WMS';

    /** Tipos de documento de los FALTANTES (FI/FIK): decisión del usuario, no cuentan para la entrega del día. */
    private const TIPOS_FALTANTE = ['FI', 'FIK'];

    /** Tope de valores por consulta: SQL Server admite 2100 parámetros. */
    private const TANDA = 1000;

    /**
     * Para cada pedido (NroDocumentoBC), qué pasó en bodega EL DÍA $dia:
     * a qué hora se escaneó el primer producto y a qué hora se cerró la
     * recepción.
     *
     * EL PROCESO, LEÍDO DEL CÓDIGO DE WMS (estudio del 12-nov-2026, ver la
     * bitácora 2026-11-12-horas-reales-sigh-e-historial-proveedores.md §9):
     *
     *  1. El bodeguero escanea cada producto en la pantalla de recepción de
     *     WMS: queda una fila en WMS.dbo.tmpRecepcion (TipoDocumento,
     *     ItemDocumento = SIGH.Documento.TipoDocumento, Item) con la hora
     *     del escaneo. Los productos por etiqueta (planta) dejan además
     *     cada código en WMS.dbo.DetEscaneoRecepcion.
     *  2. Al pulsar "cerrar", WMS.spCerrarDocumento llama a
     *     WMSspInsertarCerrarRecepcion, que crea el ingreso en
     *     SIGH.DocumentoInv (IB/IK/IC/KC, con ItemPedido = Item del pedido,
     *     Tipo = tipo del pedido y FechaCreacion = GETDATE()) y cierra el
     *     pedido (Documento.EstadoPedido = 'N').
     *
     * Por eso:
     *  - INICIO de la recepción = primer escaneo del día (tmpRecepcion o
     *    DetEscaneoRecepcion, el que sea anterior).
     *  - CIERRE = el ingreso en DocumentoInv de ese día. Se usa el ingreso
     *    y no Documento.FechaModif porque FechaCreacion de DocumentoInv no
     *    vuelve a cambiar; FechaModif sí puede cambiar por otros procesos.
     *
     * Hasta el 12-nov el portal guardaba el CIERRE como "recepción", y por
     * eso Recepción y Recibido salían con la misma hora.
     *
     * El cruce va SIEMPRE por tipo + número: los números de Item se repiten
     * entre tipos de documento (PCV 148xxx, PD 190xxx, OK 108xxx...).
     *
     * @param  array<string>  $nrosDocumentoBc
     * @param  bool  $lanzarSiFalla  true = si SIGH falla, relanza (la corrección del histórico lo necesita)
     * @return array<string, array{inicio: ?string, cierre: ?string}> solo los pedidos con algo ese día ('Y-m-d H:i:s')
     */
    public function recepcionesEnWms(array $nrosDocumentoBc, CarbonInterface $dia, bool $lanzarSiFalla = false): array
    {
        $nrosDocumentoBc = array_values(array_unique(array_filter($nrosDocumentoBc)));

        if ($nrosDocumentoBc === []) {
            return [];
        }

        // Límites del día como texto ISO con T: con la sesión en español
        // (DATEFORMAT dmy) SQL Server puede leer 'Y-m-d H:i:s' al revés.
        $desde = $dia->copy()->startOfDay()->format('Y-m-d\TH:i:s');
        $hasta = $dia->copy()->addDay()->startOfDay()->format('Y-m-d\TH:i:s');
        $wms = self::BASE_WMS;
        $faltantes = "'" . implode("','", self::TIPOS_FALTANTE) . "'";

        $resultado = [];

        try {
            foreach (array_chunk($nrosDocumentoBc, self::TANDA) as $tanda) {
                $marcas = implode(',', array_fill(0, count($tanda), '?'));

                $filas = DB::connection('sqlsrv_sigh')->select("
                    SELECT d.NroDocumentoBC AS doc,
                        (SELECT MIN(t.FechaCreacion) FROM {$wms}.dbo.tmpRecepcion t
                            WHERE t.TipoDocumento = d.TipoDocumento AND t.ItemDocumento = d.Item
                              AND t.FechaCreacion >= ? AND t.FechaCreacion < ?) AS inicio_tmp,
                        (SELECT MIN(x.FechaCreacion) FROM {$wms}.dbo.DetEscaneoRecepcion x
                            WHERE x.TipoDocumento = d.TipoDocumento AND x.ItemDocumento = d.Item
                              AND x.FechaCreacion >= ? AND x.FechaCreacion < ?) AS inicio_escaneo,
                        (SELECT MIN(di.FechaCreacion) FROM DocumentoInv di
                            WHERE di.ItemPedido = CAST(d.Item AS VARCHAR(20)) AND di.Tipo = d.TipoDocumento
                              AND di.TipoDocumento IN ('IB', 'IK', 'IC', 'KC')
                              AND di.FechaCreacion >= ? AND di.FechaCreacion < ?) AS cierre
                    FROM Documento d
                    WHERE d.NroDocumentoBC IN ({$marcas})
                      AND d.TipoDocumento NOT IN ({$faltantes})",
                    [$desde, $hasta, $desde, $hasta, $desde, $hasta, ...$tanda]
                );

                foreach ($filas as $f) {
                    $inicio = $this->menor($f->inicio_tmp, $f->inicio_escaneo);
                    $cierre = $f->cierre ? substr((string) $f->cierre, 0, 19) : null;

                    if (! $inicio && ! $cierre) {
                        continue;
                    }

                    $doc = trim((string) $f->doc);
                    // Un mismo NroDocumentoBC podría tener más de una fila
                    // en Documento: se queda con el inicio más temprano y
                    // el cierre más tardío.
                    $previo = $resultado[$doc] ?? ['inicio' => null, 'cierre' => null];
                    $resultado[$doc] = [
                        'inicio' => $this->menor($previo['inicio'], $inicio),
                        'cierre' => $this->mayor($previo['cierre'], $cierre),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('SincronizacionSighService: fallo al consultar la recepción en SIGH/WMS.', [
                'error' => $e->getMessage(),
            ]);

            // Para el job de cada minuto, "sin datos" está bien: reintenta
            // solo. Para corregir el histórico NO: un vacío se leería como
            // "no hubo recepción" y se borrarían horas buenas.
            if ($lanzarSiFalla) {
                throw $e;
            }

            return [];
        }

        return $resultado;
    }

    /**
     * Junta los pedidos del proveedor que se recibieron ese día en UNA
     * entrega del calendario (decisión del usuario, 12-nov-2026):
     *
     *  - Recepción = el PRIMER escaneo de cualquiera de sus pedidos.
     *  - Recibido  = el cierre del ÚLTIMO pedido, y solo cuando TODOS los
     *    que empezaron a recibirse ese día están cerrados. Mientras alguno
     *    siga abierto, la entrega sigue "En recepción".
     *  - Nro_Documento_Bc = el pedido que empezó primero.
     *
     * Un pedido cerrado sin escaneos (no debería pasar: el cierre de WMS
     * exige al menos un producto escaneado) cuenta con inicio = cierre.
     *
     * @param  array<string>  $docs  pedidos candidatos del proveedor
     * @param  array<string, array{inicio: ?string, cierre: ?string}>  $recepciones  salida de recepcionesEnWms()
     * @return array{documento: string, recepcion: Carbon, recibido: ?Carbon}|null  null = nada ese día
     */
    public function resumirEntrega(array $docs, array $recepciones): ?array
    {
        $delDia = [];

        foreach (array_unique($docs) as $doc) {
            $r = $recepciones[$doc] ?? null;

            if (! $r || (! $r['inicio'] && ! $r['cierre'])) {
                continue;
            }

            $delDia[$doc] = [
                'inicio' => $r['inicio'] ?? $r['cierre'],
                'cierre' => $r['cierre'],
            ];
        }

        if ($delDia === []) {
            return null;
        }

        uasort($delDia, fn ($a, $b) => strcmp($a['inicio'], $b['inicio']));
        $primero = array_key_first($delDia);

        $cierres = array_column($delDia, 'cierre');
        $todosCerrados = ! in_array(null, $cierres, true);

        return [
            'documento' => $primero,
            'recepcion' => Carbon::parse($delDia[$primero]['inicio']),
            'recibido' => $todosCerrados ? Carbon::parse(max($cierres)) : null,
        ];
    }

    private function menor(?string $a, ?string $b): ?string
    {
        $a = $a ? substr((string) $a, 0, 19) : null;
        $b = $b ? substr((string) $b, 0, 19) : null;

        if (! $a || ! $b) {
            return $a ?: $b;
        }

        return strcmp($a, $b) <= 0 ? $a : $b;
    }

    private function mayor(?string $a, ?string $b): ?string
    {
        if (! $a || ! $b) {
            return $a ?: $b;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
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
     * $fecha (12-nov-2026): el día de la entrega. Sin ella es hoy (el job
     * de cada minuto); la corrección del histórico la pasa para que la
     * ventana sea la de ESE día y no la de hoy.
     *
     * @return array<string>
     */
    public function documentosBcDelProveedor(int $idEmpresa, int $idProveedor, ?CarbonInterface $fecha = null): array
    {
        $fecha ??= now();

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
            ->where(function ($query) use ($fecha) {
                $query->whereNull('Fecha_Recepcion_Esperada')
                    ->orWhereBetween('Fecha_Recepcion_Esperada', [
                        $fecha->copy()->subDays(7)->startOfDay(),
                        $fecha->copy()->endOfDay(),
                    ]);
            })
            ->orderByDesc('Fecha_Recepcion_Esperada')
            ->pluck('Nro_Pedido')
            ->all();
    }
}
