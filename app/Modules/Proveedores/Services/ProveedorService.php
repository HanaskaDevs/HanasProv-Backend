<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Proveedores\Models\HistorialEstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProveedorService
{
    public function listarPorEmpresa(int $idEmpresa): Collection
    {
        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', true)
            ->with(['estado', 'clases', 'calificacionesCampos'])
            ->withCount([
                // Documentos ya cargados (Activo) que todavía no tienen
                // Estado_Calificacion -> lo que el admin tiene pendiente de
                // revisar en la pestaña de Documentos.
                'documentos as documentos_pendientes_calificar_count' => function ($query) {
                    $query->where('Activo', 1)->whereNull('Estado_Calificacion');
                },
                'documentos as documentos_totales_count' => function ($query) {
                    $query->where('Activo', 1);
                },
            ])
            ->get();
    }

    /**
     * Cambia el estado de un proveedor y deja constancia en el historial
     * dentro de una misma transacción.
     */
    public function cambiarEstado(Proveedor $proveedor, int $idEstadoNuevo, ?string $motivo, ?int $idUsuario): Proveedor
    {
        return DB::transaction(function () use ($proveedor, $idEstadoNuevo, $motivo, $idUsuario) {
            $idEstadoAnterior = $proveedor->Id_Estado_Proveedor;

            $proveedor->forceFill(['Id_Estado_Proveedor' => $idEstadoNuevo])->save();

            HistorialEstadoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Estado_Anterior' => $idEstadoAnterior,
                'Id_Estado_Nuevo' => $idEstadoNuevo,
                'Motivo' => $motivo,
                'Id_Usuario' => $idUsuario,
                'Fecha_Cambio' => now(),
            ]);

            return $proveedor->fresh();
        });
    }
}