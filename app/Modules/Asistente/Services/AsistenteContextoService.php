<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Ficha_Productos\Services\ProductoService;
use App\Modules\Pedidos\Services\PedidoService;
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
    ) {
    }

    public function generar(Usuario $usuario, int $idEmpresaActiva): string
    {
        if ($usuario->Tipo_Usuario === 'Proveedor') {
            return $this->contextoProveedor($usuario, $idEmpresaActiva);
        }

        return $this->contextoInterno($usuario, $idEmpresaActiva);
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
            $lineas[] = $resumenProductos['ya_bloqueado']
                ? "Estado de productos: EN REVISIÓN (bloqueados, esperando calificación de un administrador; no puede editar ni agregar productos mientras dure esto)."
                : "Estado de productos: no bloqueados, puede editar/agregar libremente.";

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

        $lineasDoc = ["Documentos requeridos por la ficha de proveedor:"];

        foreach ($tipos->take(20) as $tipo) {
            $obligatorio = $tipo->Obligatorio ? 'obligatorio' : 'opcional';
            $subido = $documentosSubidos->get($tipo->Id_Tipo_Documento);

            if ($subido) {
                $estado = $subido->Estado_Calificacion ?: 'sin calificar aún';
                $lineasDoc[] = "- {$tipo->Nombre_Documento} ({$obligatorio}): YA SUBIDO, estado: {$estado}.";
            } else {
                $lineasDoc[] = "- {$tipo->Nombre_Documento} ({$obligatorio}): NO subido todavía.";
            }
        }

        return implode("\n", $lineasDoc);
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

        return implode("\n", $lineas);
    }
}