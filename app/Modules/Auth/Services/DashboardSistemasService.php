<?php

namespace App\Modules\Auth\Services;

use App\Models\Empresa;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Asistente\Services\AsistentePresupuestoService;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Reclamos\Models\Reclamo;

/**
 * Resumen del panel de Inicio para Sistemas/Admin -> antes esa pantalla
 * solo mostraba un cartel genérico ("usa el menú lateral..."), sin
 * ningún dato real. Todo acá se acota a la empresa activa, mismo
 * criterio que el resto de las pantallas de administración (el
 * selector de empresa del header).
 */
class DashboardSistemasService
{
    public function __construct(protected AsistentePresupuestoService $presupuestoAsistente)
    {
    }

    public function obtenerResumen(int $idEmpresaActiva): array
    {
        $proveedoresActivos = Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->with('calificacionesCampos')
            ->get();

        $proveedoresPorEstado = Proveedor::where('Proveedor.Id_Empresa', $idEmpresaActiva)
            ->where('Proveedor.Activo', 1)
            ->join('Estado_Proveedor', 'Estado_Proveedor.Id_Estado_Proveedor', '=', 'Proveedor.Id_Estado_Proveedor')
            ->selectRaw('Estado_Proveedor.Nombre_Estado as estado, COUNT(*) as total')
            ->groupBy('Estado_Proveedor.Nombre_Estado')
            ->pluck('total', 'estado');

        // Ficha completa (100%) pero todavía sin una calificación general
        // definitiva (ni Aprobado ni Rechazado) -> está esperando que
        // alguien la revise por primera vez.
        $fichasPendientes = $proveedoresActivos
            ->filter(fn (Proveedor $p) => $p->Porcentaje_Completado_Ficha === 100
                && ! in_array($p->estadoGeneralCalificacionFicha(), ['Aprobado', 'Rechazado'], true))
            ->count();

        // Documentos ya registrados por el proveedor (Fecha_Registro_Documentacion
        // no nula) pero que todavía nadie calificó.
        $documentosPendientes = DocumentoProveedor::where('Activo', 1)
            ->whereNull('Estado_Calificacion')
            ->whereHas('proveedor', function ($q) use ($idEmpresaActiva) {
                $q->where('Id_Empresa', $idEmpresaActiva)->whereNotNull('Fecha_Registro_Documentacion');
            })
            ->count();

        $productosPendientes = Producto::where('Activo', 1)
            ->where('Bloqueado', 1)
            ->where('Estado_Calificacion', 'Pendiente')
            ->whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva))
            ->count();

        $reclamosAbiertos = Reclamo::where('Id_Empresa', $idEmpresaActiva)
            ->where('Estado', 'Abierto')
            ->count();

        // Los 5 pedidos abiertos con fecha de recepción esperada más
        // próxima (o ya vencida) -> a diferencia de los contadores de
        // arriba (que solo dicen "cuántos"), esto le dice a
        // Sistemas/Admin CUÁLES atender primero, sin tener que entrar
        // a Pedidos y ordenar/filtrar a mano.
        $pedidosProximos = PedidoCompra::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->where('Estado', 'Abierto')
            ->with('proveedor:Id_Proveedor,Razon_Social,Nombre_Comercial')
            ->orderBy('Fecha_Recepcion_Esperada')
            ->limit(5)
            ->get()
            ->map(fn (PedidoCompra $pedido) => [
                'nro_pedido' => $pedido->Nro_Pedido,
                'proveedor' => $pedido->proveedor?->Nombre_Comercial ?? $pedido->proveedor?->Razon_Social,
                'fecha_recepcion_esperada' => $pedido->Fecha_Recepcion_Esperada?->toDateString(),
                'vencido' => $pedido->Fecha_Recepcion_Esperada !== null && $pedido->Fecha_Recepcion_Esperada->isPast(),
            ]);

        return [
            'total_empresas' => Empresa::where('Activo', 1)->count(),
            'proveedores_por_estado' => $proveedoresPorEstado,
            'fichas_pendientes' => $fichasPendientes,
            'documentos_pendientes' => $documentosPendientes,
            'productos_pendientes' => $productosPendientes,
            'reclamos_abiertos' => $reclamosAbiertos,
            'pedidos_proximos' => $pedidosProximos,
            // Gasto del asistente contra la API de Claude. Va acá porque un
            // tope de gasto que no se puede mirar es medio inútil: cuando
            // Hana empieza a responder con su texto de respaldo, esto es lo
            // que explica por qué. NO se acota a la empresa activa a
            // propósito -> el tope es de la cuenta de Anthropic, que es una
            // sola para todo el grupo.
            'asistente_presupuesto' => $this->presupuestoAsistente->estado(),
        ];
    }
}