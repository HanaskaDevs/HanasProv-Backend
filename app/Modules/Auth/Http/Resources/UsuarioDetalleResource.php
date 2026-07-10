<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle completo de un usuario para el modal de edición: todas sus
 * empresas con su rol en cada una (no solo la empresa activa).
 */
class UsuarioDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->Id_Usuario,
            'email' => $this->Email,
            'nombre_completo' => $this->Nombre_Completo,
            'tipo_usuario' => $this->Tipo_Usuario,
            'activo' => (bool) $this->Activo,
            'empresas' => $this->usuarioEmpresas->where('Activo', true)->map(fn ($ue) => [
                'id_empresa' => $ue->Id_Empresa,
                'razon_social' => $ue->empresa->Razon_Social,
                'nombre_comercial' => $ue->empresa->Nombre_Comercial,
                'id_rol' => $ue->Id_Rol,
                'nombre_rol' => $ue->rol->Nombre_Rol,
            ])->values(),
        ];
    }
}