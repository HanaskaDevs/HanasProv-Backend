<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Reclamos\Models\Reclamo;
use Illuminate\Support\Facades\DB;

/**
 * Calificación global del proveedor, sobre 100 puntos.
 *
 * Es una nota de DESEMPEÑO y no hay que confundirla con la calificación de
 * ingreso (ficha / documentos / productos, ver CalificacionProveedorService):
 * esa decide si el proveedor entra al portal, esta mide cómo se está
 * portando una vez adentro.
 *
 * Reparto de los 100 puntos:
 *
 *   Fill rate ................ 50   promedio del % de cada pedido cerrado
 *   Auditoría de proveedor ... 15   última auditoría finalizada
 *   Documentos ............... 15   todo o nada
 *   Reclamos ................. 10   10 menos 1 por reclamo del último año
 *   Auditoría de recepción ... 10   última recepción calificada
 *
 * DOS AVISOS SOBRE CÓMO ESTÁ DEFINIDO EL CÁLCULO, porque no son
 * accidentes sino decisiones del negocio que conviene tener a la vista:
 *
 *  - LO QUE NO SE MIDIÓ NO PUNTÚA NI SE MUESTRA. Si a un proveedor no se le
 *    hizo la auditoría, ese componente no aparece y su peso se reparte entre
 *    los demás (ver calcular()). La nota sigue siendo sobre 100, pero
 *    calculada solo sobre lo medible: un proveedor con la documentación
 *    completa y nada más medido saca 100.
 *
 *  - El fill rate es el promedio SIMPLE de los porcentajes por pedido, no
 *    la razón entre todo lo recibido y todo lo pedido. Un pedido de una
 *    línea pesa igual que uno de cincuenta. Con los datos actuales la
 *    diferencia es grande (77% por promedio de pedidos contra 56% por
 *    cantidades), así que no es un detalle menor.
 */
class CalificacionGlobalService
{
    /** La nota es sobre 100, siempre, incluso cuando se renormaliza. */
    public const PESO_TOTAL = 100;

    /** Peso de cada componente, en puntos sobre 100. Suman PESO_TOTAL. */
    public const PESO_FILL_RATE = 50;
    public const PESO_AUDITORIA_PROVEEDOR = 15;
    public const PESO_DOCUMENTOS = 15;
    public const PESO_RECLAMOS = 10;
    public const PESO_AUDITORIA_RECEPCION = 10;

    /** Cada reclamo del último año descuenta 1 punto de los 10. */
    public const MESES_VENTANA_RECLAMOS = 12;

    /**
     * Solo los pedidos Cerrados entran al fill rate: un pedido abierto
     * todavía no se terminó de entregar, y contarlo en 0% castigaría al
     * proveedor por algo que aún no pasó.
     */
    public const ESTADO_PEDIDO_COMPUTABLE = 'Cerrado';

    public function __construct(protected DocumentoProveedorService $documentoService)
    {
    }

    /**
     * Nota global del proveedor con el desglose de sus cinco componentes.
     *
     * Todo se acota a la empresa del propio proveedor ($proveedor->Id_Empresa):
     * un Proveedor pertenece a UNA empresa, así que no hace falta recibir la
     * empresa activa por separado -> quien llame es responsable de haber
     * resuelto el proveedor correcto (ver el controller).
     */
    public function calcular(Proveedor $proveedor): array
    {
        $todos = [
            $this->componenteFillRate($proveedor),
            $this->componenteAuditoriaProveedor($proveedor),
            $this->componenteDocumentos($proveedor),
            $this->componenteReclamos($proveedor),
            $this->componenteAuditoriaRecepcion($proveedor),
        ];

        // Los componentes SIN MEDICIÓN no puntúan ni aparecen entre los
        // puntajes: su peso se reparte proporcionalmente entre los que sí
        // se midieron, y la nota sigue siendo sobre 100.
        //
        // POR QUÉ ASÍ y no dando el puntaje completo por defecto (que era
        // el comportamiento anterior): regalar los puntos hacía que un
        // proveedor sin auditar sacara MÁS nota que uno auditado al 80%, y
        // en la pantalla aparecía una fila "Sin medir 15/15" que en un
        // reporte se lee como si de verdad hubiera sacado 15. Renormalizando,
        // al proveedor no se le castiga por una auditoría que la empresa no
        // hizo (su nota se calcula solo sobre lo que sí se midió) y ninguna
        // fila inventada entra al reporte.
        //
        // Efecto práctico: un proveedor al que solo se le midió la
        // documentación, y la tiene completa, saca 100.
        $medidos = array_values(array_filter($todos, fn (array $c) => ! $c['sin_datos']));
        $sinDatos = array_values(array_filter($todos, fn (array $c) => $c['sin_datos']));

        // Nada medido: no hay nota que dar. Devolver 0 sería mentir (no es
        // que le haya ido mal, es que no hay con qué evaluarlo) y devolver
        // 100 también.
        if ($medidos === []) {
            return [
                'puntaje_total' => null,
                'evaluable' => false,
                'peso_evaluado' => 0,
                'componentes' => [],
                'componentes_sin_datos' => $this->resumirSinDatos($sinDatos),
            ];
        }

        $pesoEvaluado = array_sum(array_column($medidos, 'peso_original'));

        // Cada componente conserva SU PESO ORIGINAL: documentación vale 15
        // siempre, reclamos 10 siempre. La renormalización se aplica al
        // TOTAL, no a las filas.
        //
        // Se hizo así después de probar lo contrario: al escalar los pesos
        // de cada fila, un proveedor con solo documentación y reclamos
        // medidos mostraba "Documentación vale 60 pts", que es correcto
        // aritméticamente y desconcertante de leer. Con el peso original en
        // la fila, el proveedor ve siempre la misma vara ("15 de 15") y el
        // total dice sobre cuánto se lo evaluó.
        $componentes = array_map(fn (array $componente) => [
            'clave' => $componente['clave'],
            'etiqueta' => $componente['etiqueta'],
            'peso' => $componente['peso_original'],
            'porcentaje' => $componente['porcentaje'],
            'puntaje' => round($componente['porcentaje'] / 100 * $componente['peso_original'], 2),
            'detalle' => $componente['detalle'],
        ], $medidos);

        $obtenido = array_sum(array_column($componentes, 'puntaje'));

        return [
            // La nota es lo obtenido sobre lo evaluado, llevado a 100.
            'puntaje_total' => round($obtenido / $pesoEvaluado * self::PESO_TOTAL, 2),
            // Lo obtenido y lo evaluado en crudo, para que un reporte pueda
            // mostrar "67.9 de 85 puntos evaluados" sin recalcular nada.
            'puntaje_obtenido' => round($obtenido, 2),
            'evaluable' => true,
            // Cuánto del esquema original de 100 puntos se pudo medir. Sirve
            // para que un reporte pueda decir "nota calculada sobre el 85%
            // del esquema" en vez de fingir que se evaluó todo.
            'peso_evaluado' => $pesoEvaluado,
            'componentes' => $componentes,
            'componentes_sin_datos' => $this->resumirSinDatos($sinDatos),
        ];
    }

    /**
     * Los componentes que no se pudieron medir, en forma reducida. No van
     * con puntaje porque no tienen: son una nota al pie ("todavía no se le
     * hizo la auditoría"), no una fila del cálculo.
     */
    protected function resumirSinDatos(array $sinDatos): array
    {
        return array_map(fn (array $c) => [
            'clave' => $c['clave'],
            'etiqueta' => $c['etiqueta'],
            'peso_original' => $c['peso_original'],
            'motivo' => $c['detalle'],
        ], $sinDatos);
    }

    /**
     * Fill rate: se saca el porcentaje de CADA pedido cerrado por separado
     * y después se promedian esos porcentajes.
     *
     * Dentro de un pedido, el tope por línea es deliberado: si en una línea
     * entregaron más de lo pedido, esa línea cuenta como 100% y no más. Sin
     * el tope, una sobre-entrega tapa el faltante de otra línea y el pedido
     * parece cumplido cuando no lo estuvo. Hoy hay 86 líneas en esa
     * situación, así que el tope cambia el resultado de verdad.
     */
    protected function componenteFillRate(Proveedor $proveedor): array
    {
        $porPedido = $this->fillRatePorPedidoQuery($proveedor->Id_Proveedor, $proveedor->Id_Empresa);

        $fila = DB::query()
            ->fromSub($porPedido, 't')
            ->selectRaw('AVG(t.porcentaje) AS promedio, COUNT(*) AS pedidos')
            ->first();

        $pedidos = (int) ($fila->pedidos ?? 0);

        // Sin pedidos cerrados no hay nada que medir. Se da el puntaje
        // completo por el mismo criterio que las auditorías: no se castiga
        // al proveedor por no haber tenido movimiento todavía.
        if ($pedidos === 0) {
            return $this->componente(
                'fill_rate',
                'Fill rate de entregas',
                self::PESO_FILL_RATE,
                porcentaje: null,
                detalle: 'Todavía no hay pedidos cerrados para medir',
                sinDatos: true,
            );
        }

        $porcentaje = round((float) $fila->promedio, 2);

        return $this->componente(
            'fill_rate',
            'Fill rate de entregas',
            self::PESO_FILL_RATE,
            porcentaje: $porcentaje,
            detalle: "Promedio de {$pedidos} pedido(s) cerrado(s)",
        );
    }

    /**
     * Subconsulta con un porcentaje por pedido cerrado. Se usa tanto para
     * el promedio de la nota como para mostrarle al proveedor la
     * calificación de cada pedido en su pantalla de Pedidos -> una sola
     * definición del cálculo, así las dos pantallas nunca discrepan.
     */
    protected function fillRatePorPedidoQuery(int $idProveedor, int $idEmpresa)
    {
        return DB::table('Detalle_Pedido_Compra as d')
            ->join('Pedido_Compra as pc', 'pc.Id_Pedido_Compra', '=', 'd.Id_Pedido_Compra')
            ->where('pc.Id_Proveedor', $idProveedor)
            ->where('pc.Id_Empresa', $idEmpresa)
            ->where('pc.Activo', 1)
            ->where('pc.Estado', self::ESTADO_PEDIDO_COMPUTABLE)
            ->groupBy('pc.Id_Pedido_Compra')
            ->havingRaw('SUM(d.Cantidad) > 0')
            ->selectRaw('pc.Id_Pedido_Compra AS id_pedido')
            ->selectRaw(
                'SUM(CASE WHEN ISNULL(d.Cantidad_Recibida, 0) > d.Cantidad'
                .' THEN d.Cantidad ELSE ISNULL(d.Cantidad_Recibida, 0) END)'
                .' / SUM(d.Cantidad) * 100 AS porcentaje'
            );
    }

    /**
     * Fill rate de pedidos puntuales, para decorar la lista de Pedidos.
     * Devuelve [id_pedido => porcentaje]. Un pedido que no esté cerrado no
     * aparece en el resultado (no tiene calificación todavía).
     */
    public function fillRatePorPedido(int $idProveedor, int $idEmpresa): array
    {
        return $this->fillRatePorPedidoQuery($idProveedor, $idEmpresa)
            ->get()
            ->mapWithKeys(fn ($fila) => [(int) $fila->id_pedido => round((float) $fila->porcentaje, 2)])
            ->all();
    }

    /**
     * Auditoría de proveedor: la ÚLTIMA finalizada manda. No se promedian
     * las anteriores a propósito -> la auditoría mide el estado actual del
     * proveedor, y arrastrar una auditoría vieja mala después de que el
     * proveedor corrigió no reflejaría la realidad.
     */
    protected function componenteAuditoriaProveedor(Proveedor $proveedor): array
    {
        $auditoria = Auditoria::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Id_Empresa', $proveedor->Id_Empresa)
            ->where('Estado', 'Finalizada')
            ->whereNotNull('Porcentaje_Cumplimiento')
            ->orderByDesc('Fecha_Auditoria')
            ->orderByDesc('Id_Auditoria')
            ->first();

        if (! $auditoria) {
            return $this->componente(
                'auditoria_proveedor',
                'Auditoría de proveedor',
                self::PESO_AUDITORIA_PROVEEDOR,
                porcentaje: null,
                detalle: 'Todavía no se le hizo la auditoría de proveedor',
                sinDatos: true,
            );
        }

        $porcentaje = (float) $auditoria->Porcentaje_Cumplimiento;

        return $this->componente(
            'auditoria_proveedor',
            'Auditoría de proveedor',
            self::PESO_AUDITORIA_PROVEEDOR,
            porcentaje: $porcentaje,
            detalle: 'Auditoría del '.$auditoria->Fecha_Auditoria?->format('d/m/Y'),
        );
    }

    /**
     * Documentos: todo o nada. Los 15 puntos se dan solo si TODOS los
     * documentos obligatorios que le corresponden están aprobados y no
     * vencidos; si falta uno, o uno está rechazado, o uno caducó, son 0.
     *
     * Un documento sin Fecha_Caducidad no vence nunca (ej. el RUC), así que
     * cuenta como vigente. Para los tipos que admiten varios archivos
     * alcanza con que UNO esté aprobado y vigente.
     */
    protected function componenteDocumentos(Proveedor $proveedor): array
    {
        $obligatorios = $this->documentoService->tiposObligatoriosAplicables($proveedor);

        if ($obligatorios->isEmpty()) {
            return $this->componente(
                'documentos',
                'Documentación',
                self::PESO_DOCUMENTOS,
                porcentaje: null,
                detalle: 'Este proveedor no tiene documentos obligatorios que exigir',
                sinDatos: true,
            );
        }

        $idsConDocumentoValido = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Aprobado')
            ->where(function ($query) {
                $query->whereNull('Fecha_Caducidad')
                    ->orWhereRaw('CONVERT(date, Fecha_Caducidad) >= CONVERT(date, ?)', [now()->toDateString()]);
            })
            ->distinct()
            ->pluck('Id_Tipo_Documento');

        $faltantes = $obligatorios->reject(
            fn ($tipo) => $idsConDocumentoValido->contains($tipo->Id_Tipo_Documento)
        );

        $completa = $faltantes->isEmpty();
        $total = $obligatorios->count();

        return $this->componente(
            'documentos',
            'Documentación',
            self::PESO_DOCUMENTOS,
            porcentaje: $completa ? 100.0 : 0.0,
            detalle: $completa
                ? "Los {$total} documentos obligatorios están aprobados y vigentes"
                : $faltantes->count()." de {$total} sin aprobar o vencido(s): ".$faltantes->pluck('Nombre_Documento')->implode(', '),
        );
    }

    /**
     * Reclamos: se arranca con los 10 puntos y cada reclamo del último año
     * descuenta 1. Con 10 reclamos la sección queda en 0 (no baja de ahí,
     * nunca resta de las otras secciones).
     *
     * Cuentan todos los reclamos de la ventana sin importar su estado: un
     * reclamo cerrado igual ocurrió, y cerrarlo no lo borra de la historia.
     * La ventana de 12 meses es lo que permite recuperar la nota portándose
     * bien, en vez de arrastrar un reclamo viejo para siempre.
     */
    protected function componenteReclamos(Proveedor $proveedor): array
    {
        $desde = now()->subMonths(self::MESES_VENTANA_RECLAMOS);

        $reclamos = Reclamo::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Id_Empresa', $proveedor->Id_Empresa)
            ->where('Activo', 1)
            ->where('Fecha_Creacion', '>=', $desde->format('Y-m-d\TH:i:s'))
            ->count();

        $puntaje = max(0, self::PESO_RECLAMOS - $reclamos);

        return $this->componente(
            'reclamos',
            'Reclamos',
            self::PESO_RECLAMOS,
            porcentaje: $puntaje / self::PESO_RECLAMOS * 100,
            detalle: $reclamos === 0
                ? 'Sin reclamos en los últimos 12 meses'
                : "{$reclamos} reclamo(s) en los últimos 12 meses",
        );
    }

    /**
     * Auditoría de recepción (FGH04.15.05-1): igual que la de proveedor,
     * manda la última finalizada, y si no hay ninguna se da el puntaje
     * completo. Acá el porcentaje ya viene guardado en la tabla.
     */
    protected function componenteAuditoriaRecepcion(Proveedor $proveedor): array
    {
        $recepcion = CalificacionRecepcion::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Id_Empresa', $proveedor->Id_Empresa)
            ->where('Estado', 'Finalizada')
            ->whereNotNull('Porcentaje_Obtenido')
            ->orderByDesc('Fecha_Recepcion')
            ->orderByDesc('Id_Calificacion_Recepcion')
            ->first();

        if (! $recepcion) {
            return $this->componente(
                'auditoria_recepcion',
                'Auditoría de recepción',
                self::PESO_AUDITORIA_RECEPCION,
                porcentaje: null,
                detalle: 'Todavía no se le hizo la auditoría de recepción',
                sinDatos: true,
            );
        }

        $porcentaje = (float) $recepcion->Porcentaje_Obtenido;

        return $this->componente(
            'auditoria_recepcion',
            'Auditoría de recepción',
            self::PESO_AUDITORIA_RECEPCION,
            porcentaje: $porcentaje,
            detalle: 'Recepción del '.$recepcion->Fecha_Recepcion?->format('d/m/Y'),
        );
    }

    /**
     * Forma única de cada componente, para que el frontend reciba siempre
     * las mismas claves sin importar de dónde salió el número.
     *
     * 'porcentaje' es el cumplimiento de ESA sección (0-100) y 'puntaje' es
     * lo que aporta a la nota global: separarlos permite mostrar "80% de la
     * auditoría" y "12 de 15 puntos" sin que la interfaz recalcule nada.
     */
    protected function componente(
        string $clave,
        string $etiqueta,
        int $peso,
        ?float $porcentaje,
        string $detalle,
        bool $sinDatos = false,
    ): array {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            // Peso del esquema original. calcular() lo reescala si hubo
            // componentes sin datos; acá NO se calcula el puntaje porque
            // todavía no se sabe el factor de renormalización.
            'peso_original' => $peso,
            'porcentaje' => $porcentaje === null ? null : round($porcentaje, 2),
            'detalle' => $detalle,
            'sin_datos' => $sinDatos,
        ];
    }
}
