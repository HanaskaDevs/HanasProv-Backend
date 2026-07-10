<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape para el panel de administración de usuarios externos (Proveedores).
 * "proveedor" es el registro Proveedor de ESTA empresa específicamente
 * (un mismo usuario puede tener otro Proveedor distinto en otra empresa).
 */
class UsuarioExternoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vinculoEmpresa = $this->usuarioEmpresas->first();
        $proveedorEmpresa = $this->whenLoaded('proveedores', fn () => $this->proveedores->first());

        return [
            'id' => $this->Id_Usuario,
            'email' => $this->Email,
            'nombre_completo' => $this->Nombre_Completo,
            'activo' => (bool) $this->Activo,
            'requiere_activacion' => (bool) $this->Requiere_Cambio_Password,
            'ultimo_acceso' => $this->Ultimo_Acceso,
            'ficha_completada' => (bool) $proveedorEmpresa,
            'proveedor' => $proveedorEmpresa ? [
                'id_proveedor' => $proveedorEmpresa->Id_Proveedor,
                'razon_social' => $proveedorEmpresa->Razon_Social,
                'porcentaje_completado_ficha' => $proveedorEmpresa->Porcentaje_Completado_Ficha,
            ] : null,
            'rol' => $vinculoEmpresa ? [
                'id_rol' => $vinculoEmpresa->Id_Rol,
                'nombre_rol' => $vinculoEmpresa->rol->Nombre_Rol,
            ] : null,
            'fecha_creacion' => $this->Fecha_Creacion,
        ];
    }
}