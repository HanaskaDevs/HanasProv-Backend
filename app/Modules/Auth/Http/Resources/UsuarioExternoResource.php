<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape para el panel de administración de usuarios externos (Proveedores).
 * Antes de que completen la Ficha de Proveedor, "proveedor" viene null.
 */
class UsuarioExternoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vinculoEmpresa = $this->usuarioEmpresas->first();

        return [
            'id' => $this->Id_Usuario,
            'email' => $this->Email,
            'nombre_completo' => $this->Nombre_Completo,
            'activo' => (bool) $this->Activo,
            'requiere_activacion' => (bool) $this->Requiere_Cambio_Password,
            'ultimo_acceso' => $this->Ultimo_Acceso,
            'ficha_completada' => $this->Id_Proveedor !== null,
            'proveedor' => $this->whenLoaded('proveedor', fn () => $this->proveedor ? [
                'id_proveedor' => $this->proveedor->Id_Proveedor,
                'razon_social' => $this->proveedor->Razon_Social,
                'porcentaje_completado_ficha' => $this->proveedor->Porcentaje_Completado_Ficha,
            ] : null),
            'rol' => $vinculoEmpresa ? [
                'id_rol' => $vinculoEmpresa->Id_Rol,
                'nombre_rol' => $vinculoEmpresa->rol->Nombre_Rol,
            ] : null,
            'fecha_creacion' => $this->Fecha_Creacion,
        ];
    }
}
