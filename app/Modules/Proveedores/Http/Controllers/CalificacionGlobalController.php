<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Calificación global (de desempeño) del proveedor.
 *
 * Dos vistas de lo mismo:
 *  - El proveedor ve SU nota  -> GET /mi-calificacion-global
 *  - El personal interno ve la de un proveedor de su empresa activa
 *                             -> GET /proveedores/{proveedor}/calificacion-global
 *
 * El id del proveedor NO se resuelve con route-model binding: llega como
 * entero y se busca acotado a la empresa activa. El binding directo devuelve
 * el modelo sin importar a qué empresa pertenece, y eso rompe el
 * aislamiento entre empresas del grupo.
 */
class CalificacionGlobalController extends Controller
{
    /** Reporte de fill rate: hasta cuántos días atrás se puede filtrar por fecha de recepción. */
    public const DIAS_ATRAS_FILTRO_FILL_RATE = 5;

    public function __construct(protected CalificacionGlobalService $calificacionService)
    {
    }

    /**
     * La nota del propio proveedor autenticado, en su empresa activa.
     */
    public function miCalificacion(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo un usuario proveedor tiene calificación propia.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresa)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado a la empresa activa.');
        }

        return response()->json([
            ...$this->calificacionService->calcular($proveedor),
            // Para saber si hay que mostrar el cartel de "ya es proveedor
            // aprobado". Va acá y no en /mi-ficha porque el cartel vive en
            // esta misma pantalla y así no hace falta una petición extra.
            'felicitacion_pendiente' => (int) $proveedor->Id_Estado_Proveedor === EstadoProveedor::APROBADO
                && ! $proveedor->Felicitacion_Calificacion_Mostrada,
        ]);
    }

    /**
     * Marca que al proveedor ya se le mostró el cartel de felicitación, para
     * que no vuelva a aparecer.
     *
     * Se marca cuando el cartel SE MOSTRÓ, no cuando el proveedor lo cierra:
     * si esperáramos el cierre, quien navega rápido sin cerrarlo lo volvería
     * a ver en cada visita, que es justamente lo que se está arreglando. El
     * riesgo del otro lado (que se pierda un cartel que nadie llegó a leer)
     * es menor: es una felicitación, no una notificación con información que
     * el proveedor necesite.
     */
    public function marcarFelicitacionVista(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo un usuario proveedor tiene este cartel.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresa)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado a la empresa activa.');
        }

        $proveedor->forceFill(['Felicitacion_Calificacion_Mostrada' => true])->save();

        return response()->json(['message' => 'Listo.']);
    }

    /**
     * La nota de un proveedor puntual, para el personal interno.
     */
    public function mostrar(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->verificarPuedeVer($request->user(), $idEmpresa);

        $modelo = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->find($proveedor);

        if (! $modelo) {
            throw new NotFoundHttpException('El proveedor no existe en la empresa activa.');
        }

        return response()->json([
            'id_proveedor' => $modelo->Id_Proveedor,
            'razon_social' => $modelo->Razon_Social,
            'nombre_comercial' => $modelo->Nombre_Comercial,
            ...$this->calificacionService->calcular($modelo),
        ]);
    }

    /**
     * Notas de TODOS los proveedores activos de la empresa activa, de una
     * sola vez.
     *
     * Existe para que la pantalla de Calificación de Proveedores y el
     * reporte comparativo no tengan que pedir la nota proveedor por
     * proveedor (con 20 proveedores serían 20 peticiones y 20 spinners).
     *
     * OJO CON EL COSTO: calcular una nota son ~7 consultas, así que esto es
     * O(n) en consultas sobre la cantidad de proveedores. Con las decenas de
     * proveedores que maneja una empresa del grupo es instantáneo; si algún
     * día son cientos, acá es donde hay que meter cacheo o precálculo, no en
     * el service.
     */
    public function lote(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->verificarPuedeVer($request->user(), $idEmpresa);

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->orderBy('Razon_Social')
            ->get();

        return response()->json(
            $proveedores->map(fn (Proveedor $proveedor) => [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'razon_social' => $proveedor->Razon_Social,
                'nombre_comercial' => $proveedor->Nombre_Comercial,
                'ruc' => $proveedor->Ruc,
                ...$this->calificacionService->calcular($proveedor),
            ])->values()
        );
    }

    /**
     * Reporte de Cumplimiento de entregas con filtros (05-nov-2026):
     * fecha de recepción desde/hasta (la semana la arma el frontend como un
     * rango lunes-domingo) y proveedor. Devuelve el fill rate de cada
     * proveedor = promedio del % de sus pedidos cerrados en el rango (la
     * misma cuenta que la nota) y, si se pidió un proveedor, el detalle de
     * sus pedidos para el seguimiento.
     *
     * Una sola consulta agrupada, no la nota completa por proveedor: acá
     * solo hace falta el fill rate.
     */
    public function reporteFillRate(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->verificarPuedeVer($request->user(), $idEmpresa);

        // Las fechas solo pueden ir hasta DIAS_ATRAS_FILTRO_FILL_RATE días
        // atrás (05-nov-2026, pedido del usuario); el frontend tiene el mismo tope.
        $minimo = now()->subDays(self::DIAS_ATRAS_FILTRO_FILL_RATE)->toDateString();
        $hoy = now()->toDateString();
        $mensajeRango = 'Solo se puede filtrar por fecha de recepción hasta '
            .self::DIAS_ATRAS_FILTRO_FILL_RATE.' días atrás.';

        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$minimo, 'before_or_equal:'.$hoy],
            // 'after_or_equal:desde' solo si vino "desde": sin él, Laravel compara
            // contra la palabra 'desde' como fecha y rechaza un "hasta" válido.
            'hasta' => array_merge(
                ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$minimo, 'before_or_equal:'.$hoy],
                $request->filled('desde') ? ['after_or_equal:desde'] : [],
            ),
            'id_proveedor' => ['nullable', 'integer'],
        ], [
            'desde.after_or_equal' => $mensajeRango,
            'desde.before_or_equal' => 'La fecha "desde" no puede ser posterior a hoy.',
            'hasta.before_or_equal' => 'La fecha "hasta" no puede ser posterior a hoy.',
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde" ni de hace más de '
                .self::DIAS_ATRAS_FILTRO_FILL_RATE.' días.',
        ]);

        $idProveedor = isset($filtros['id_proveedor']) ? (int) $filtros['id_proveedor'] : null;

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->orderBy('Razon_Social')
            ->get(['Id_Proveedor', 'Razon_Social', 'Nombre_Comercial', 'Ruc']);

        if ($idProveedor !== null && ! $proveedores->contains('Id_Proveedor', $idProveedor)) {
            throw new NotFoundHttpException('Proveedor no encontrado en esta empresa.');
        }

        $pedidos = $this->calificacionService->fillRatePedidosParaReporte(
            $idEmpresa,
            $filtros['desde'] ?? null,
            $filtros['hasta'] ?? null,
            $idProveedor,
        );

        $nombres = $proveedores->keyBy('Id_Proveedor');
        // Solo proveedores activos de la empresa, igual que la nota.
        $porProveedor = $pedidos->filter(fn ($p) => $nombres->has($p->id_proveedor))->groupBy('id_proveedor');

        $resumen = $porProveedor->map(fn ($suyos, $id) => [
            'id_proveedor' => (int) $id,
            'razon_social' => $nombres[$id]->Razon_Social,
            'pedidos' => $suyos->count(),
            'porcentaje' => round($suyos->avg('porcentaje'), 2),
        ])->values();

        // Los que no tienen pedidos cerrados en el rango: se listan aparte en
        // la pantalla (no es un 0, es que no hay nada que medir). Si se
        // filtró un proveedor, solo importa ese.
        $sinPedidos = $proveedores
            ->when($idProveedor !== null, fn ($c) => $c->where('Id_Proveedor', $idProveedor))
            ->reject(fn (Proveedor $p) => $porProveedor->has($p->Id_Proveedor))
            ->map(fn (Proveedor $p) => $p->Razon_Social ?? 'Sin razón social')
            ->values();

        return response()->json([
            'desde' => $filtros['desde'] ?? null,
            'hasta' => $filtros['hasta'] ?? null,
            'proveedores' => $resumen,
            'sin_pedidos' => $sinPedidos,
            // Lista para el filtro de proveedor de la pantalla.
            'opciones_proveedor' => $proveedores->map(fn (Proveedor $p) => [
                'id_proveedor' => $p->Id_Proveedor,
                'razon_social' => $p->Razon_Social,
                'ruc' => $p->Ruc,
            ])->values(),
            'detalle' => $idProveedor !== null ? $pedidos->values() : [],
        ]);
    }

    /**
     * Quién puede ver la nota de un proveedor: Sistemas y Admin porque
     * administran, Calidad porque las auditorías y la documentación son
     * suyas, y Compras porque el fill rate (la mitad de la nota) es
     * justamente lo que negocia. Guardia queda fuera: solo marca arribos.
     */
    protected function verificarPuedeVer(Usuario $usuario, int $idEmpresa): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos pueden ver la calificación de un proveedor.');
        }

        $puede = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCalidad($idEmpresa)
            || $usuario->esCompras($idEmpresa);

        if (! $puede) {
            throw new AccessDeniedHttpException('No tienes permisos para ver la calificación de proveedores.');
        }
    }
}
