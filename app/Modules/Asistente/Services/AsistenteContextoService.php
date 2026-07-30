<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Ficha_Productos\Services\ProductoService;
use App\Modules\Pedidos\Services\PedidoService;
use App\Modules\Pedidos\Services\PedidoInternoService;
use App\Modules\Reclamos\Services\ReclamoService;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Reclamos\Models\Reclamo;

class AsistenteContextoService
{
    public function __construct(
        protected ProductoService $productoService,
        protected PedidoService $pedidoService,
        protected ReclamoService $reclamoService,
        protected PedidoInternoService $pedidoInternoService,
    ) {
    }

    public function generar(Usuario $usuario, int $idEmpresaActiva): string
    {
        $contenido = $usuario->Tipo_Usuario === 'Proveedor'
            ? $this->contextoProveedor($usuario, $idEmpresaActiva)
            : $this->contextoInterno($usuario, $idEmpresaActiva);

        return $this->instruccionesNavegacion($usuario, $idEmpresaActiva) . "\n\n" . $contenido;
    }

    /**
     * Le dice al modelo EXACTAMENTE qué rutas existen para este usuario y el
     * protocolo del marcador [IR_A:/ruta] que el frontend intercepta para
     * navegar automáticamente apenas el bot responde. Nunca debe inventar
     * una ruta que no esté en esta lista.
     */
    protected function instruccionesNavegacion(Usuario $usuario, int $idEmpresaActiva): string
    {
        $secciones = $this->seccionesDisponibles($usuario, $idEmpresaActiva);

        if (empty($secciones)) {
            return 'INSTRUCCIONES DE NAVEGACIÓN: este usuario no tiene secciones navegables conocidas, no uses el marcador [IR_A:].';
        }

        $lista = collect($secciones)
            ->map(fn ($label, $ruta) => "- {$ruta} : {$label}")
            ->implode("\n");

        return "INSTRUCCIONES DE NAVEGACIÓN:\n"
            . "Si el usuario pregunta cómo hacer algo, o por el estado de algo, y eso corresponde claramente a una de las secciones de abajo, "
            . "termina tu respuesta con el marcador [IR_A:/ruta] (una sola vez, al final, sin nada de texto después). "
            . "El usuario será llevado automáticamente a esa sección apenas termines de responder, así que en el mismo mensaje explícale también el dato "
            . "concreto que pidió, usando SIEMPRE los números/nombres reales del bloque CONTEXTO REAL DEL USUARIO (nunca inventes cifras). "
            . "No uses el marcador si el usuario solo está conversando o pregunta algo general que no corresponde a ninguna sección. "
            . "Usa EXCLUSIVAMENTE estas rutas, nunca otras ni inventadas:\n"
            . $lista;
    }

    protected function seccionesDisponibles(Usuario $usuario, int $idEmpresaActiva): array
    {
        if ($usuario->Tipo_Usuario === 'Proveedor') {
            return [
                '/mi-ficha' => 'Mi Ficha (datos generales del proveedor)',
                '/documentos' => 'Documentación (subir/ver documentos y su estado de calificación, incluidos rechazados)',
                '/productos' => 'Ficha Productos (catálogo de productos del proveedor)',
                '/calificacion' => 'Calificación (estado de la postulación/calificación del proveedor)',
                '/pedidos' => 'Pedidos (pedidos de compra y su % de entrega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
            ];
        }

        $rol = $usuario->empresas()->where('Empresa.Id_Empresa', $idEmpresaActiva)->first()?->pivot->rol->Nombre_Rol;

        return match ($rol) {
            'Sistemas' => [
                '/empresas' => 'Empresas',
                '/usuarios/internos' => 'Usuarios Internos',
                '/usuarios/proveedores' => 'Cuentas de Proveedores',
                '/proveedores' => 'Proveedores',
                '/pedidos' => 'Pedidos (vista interna por bodega, % de entrega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/calificacion' => 'Calificaciones',
                '/auditorias' => 'Auditorías',
                '/configuraciones' => 'Configuraciones',
            ],
            'Admin' => [
                '/usuarios/proveedores' => 'Cuentas de Proveedores',
                '/proveedores' => 'Calificación Proveedores',
                '/pedidos' => 'Pedidos (vista interna por bodega, % de entrega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/auditorias' => 'Auditorías',
            ],
            'Calidad', 'Compras' => [
                '/pedidos' => 'Pedidos (vista interna por bodega, % de entrega, proveedores pendientes de entrega hoy)',
                '/auditorias' => 'Auditorías',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
            ],
            default => [],
        };
    }

    protected function contextoProveedor(Usuario $usuario, int $idEmpresaActiva): string
    {
        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            return "El usuario es un proveedor pero todavía no tiene una Ficha asociada a esta empresa.";
        }

        $empresa = $proveedor->empresa;

        $lineas = [];
        $lineas[] = "El usuario que está hablando es un PROVEEDOR EXTERNO, no un empleado de Hanaska.";
        $lineas[] = "Nombre de SU PROPIA empresa (la del proveedor): {$proveedor->Razon_Social}";
        $lineas[] = "Empresa del grupo Hanaska con la que este proveedor trabaja actualmente: " . ($empresa->Razon_Social ?? 'no especificada');
        $lineas[] = "Ficha de proveedor completada: {$proveedor->Porcentaje_Completado_Ficha}%";
        $lineas[] = "REGLA IMPORTANTE: los proveedores NUNCA pueden crear reclamos. Solo el personal interno de Hanaska (Sistemas, Admin, Compras) puede crear un reclamo. El proveedor únicamente puede VER y RESPONDER los reclamos que ya le hayan creado.";

        try {
            $lineas[] = $this->contextoDocumentos($proveedor);
        } catch (\Throwable $e) {
            $lineas[] = "No se pudo obtener el estado de documentos en este momento.";
        }

       try {
            $resumenProductos = $this->productoService->resumenRegistro($usuario, $idEmpresaActiva);
            $lineas[] = "Total de productos registrados: {$resumenProductos['total_productos']}";
            $lineas[] = $resumenProductos['productos_en_revision'] > 0
                ? "Productos en revisión: {$resumenProductos['productos_en_revision']} (esos puntuales están bloqueados hasta que un administrador los califique; el resto del catálogo se puede seguir editando y se pueden registrar más lotes en paralelo)."
                : "Estado de productos: ninguno en revisión, puede editar/agregar libremente.";

            $incompletos = $resumenProductos['productos_incompletos'] ?? [];
            if (! empty($incompletos)) {
                $total = count($incompletos);
                $muestra = array_slice($incompletos, 0, 5);
                $lineas[] = "Productos con documentos obligatorios faltantes: {$total} en total. Ejemplos: " . implode(', ', $muestra)
                    . ($total > 5 ? " (y " . ($total - 5) . " más)." : ".");
            }
        } catch (\Throwable $e) {
            $lineas[] = "No se pudo obtener el estado de productos en este momento.";
        }
        try {
            $abiertos = $this->pedidoService->listar($usuario, $idEmpresaActiva, 'Abierto')->count();
            $cerrados = $this->pedidoService->listar($usuario, $idEmpresaActiva, 'Cerrado')->count();
            $lineas[] = "Pedidos de compra abiertos: {$abiertos}. Pedidos cerrados: {$cerrados}.";
        } catch (\Throwable $e) {
            $lineas[] = "No se pudo obtener el estado de pedidos en este momento.";
        }

        try {
            $reclamosAbiertos = $this->reclamoService->listarProveedor($usuario, $idEmpresaActiva, 'Abierto')->count();
            $lineas[] = "Reclamos abiertos sobre esta empresa: {$reclamosAbiertos}.";
        } catch (\Throwable $e) {
            $lineas[] = "No se pudo obtener el estado de reclamos en este momento.";
        }

        return implode("\n", $lineas);
    }

    /**
     * Arma el detalle real de documentos: cuáles tipos existen en el
     * catálogo (obligatorios vs opcionales) y cuáles de esos ya subió
     * este proveedor específico, con su estado de calificación.
     */
   protected function contextoDocumentos($proveedor): string
    {
        $tipos = TipoDocumento::where('Activo', 1)->orderBy('Categoria')->get();

        if ($tipos->isEmpty()) {
            return "No hay tipos de documento configurados en el sistema.";
        }

        $documentosSubidos = $proveedor->documentos()
            ->where('Activo', 1)
            ->get()
            ->keyBy('Id_Tipo_Documento');

        $rechazados = [];
        $lineasDoc = [];

        foreach ($tipos->take(20) as $tipo) {
            $obligatorio = $tipo->Obligatorio ? 'obligatorio' : 'opcional';
            $subido = $documentosSubidos->get($tipo->Id_Tipo_Documento);

            if ($subido) {
                $estado = $subido->Estado_Calificacion ?: 'sin calificar aún';
                $lineasDoc[] = "- {$tipo->Nombre_Documento} ({$obligatorio}): YA SUBIDO, estado: {$estado}.";

                if ($estado === 'Rechazado') {
                    $rechazados[] = $tipo->Nombre_Documento;
                }
            } else {
                $lineasDoc[] = "- {$tipo->Nombre_Documento} ({$obligatorio}): NO subido todavía.";
            }
        }

        $totalRechazados = count($rechazados);
        $resumen = $totalRechazados > 0
            ? "RESUMEN EXACTO: tiene {$totalRechazados} documento(s) RECHAZADO(S): " . implode(', ', $rechazados) . '.'
            : 'RESUMEN EXACTO: no tiene ningún documento rechazado actualmente.';

        return $resumen . "\n\nDocumentos requeridos por la ficha de proveedor:\n" . implode("\n", $lineasDoc);
    }

    protected function contextoInterno(Usuario $usuario, int $idEmpresaActiva): string
    {
        $lineas = [];
        $lineas[] = "Usuario interno de Hanaska, rol dentro de la empresa activa: " . ($usuario->empresas()->where('Empresa.Id_Empresa', $idEmpresaActiva)->first()?->pivot->rol->Nombre_Rol ?? 'desconocido');

        try {
            $pedidosAbiertos = PedidoCompra::where('Id_Empresa', $idEmpresaActiva)->where('Estado', 'Abierto')->count();
            $lineas[] = "Pedidos de compra abiertos en la empresa: {$pedidosAbiertos}.";
        } catch (\Throwable $e) {
        }

        try {
            $productosPendientes = Producto::whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva))
                ->where('Estado_Calificacion', 'Pendiente')
                ->count();
            $lineas[] = "Productos pendientes de calificar en la empresa: {$productosPendientes}.";
        } catch (\Throwable $e) {
        }

        try {
            $reclamosAbiertos = Reclamo::where('Id_Empresa', $idEmpresaActiva)->where('Estado', 'Abierto')->where('Activo', 1)->count();
            $lineas[] = "Reclamos abiertos en la empresa: {$reclamosAbiertos}.";
        } catch (\Throwable $e) {
        }

        try {
            $pendientesHoy = $this->pedidoInternoService->proveedoresPendientesHoy($usuario, $idEmpresaActiva);
            $totalPendientesHoy = count($pendientesHoy);

            if ($totalPendientesHoy > 0) {
                $nombres = collect($pendientesHoy)->pluck('proveedor')->unique()->take(10)->implode(', ');
                $lineas[] = "RESUMEN EXACTO: {$totalPendientesHoy} proveedor(es) tienen entrega programada para HOY y aún no han entregado nada (0% recibido): {$nombres}.";
            } else {
                $lineas[] = 'RESUMEN EXACTO: ningún proveedor con entrega programada para hoy está en 0% de recepción.';
            }
        } catch (\Throwable $e) {
        }

        return implode("\n", $lineas);
    }
}