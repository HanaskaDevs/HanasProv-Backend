<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape pensado para el panel de administración de usuarios internos
 * (listado y detalle), mostrando el rol que tiene DENTRO de la empresa activa.
 */
class UsuarioInternoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // El controller debe cargar 'usuarioEmpresas.rol' filtrado a la empresa activa.
        $vinculoEmpresa = $this->usuarioEmpresas->first();

        return [
            'id' => $this->Id_Usuario,
            'email' => $this->Email,
            'nombre_completo' => $this->Nombre_Completo,
            'cargo' => $this->Cargo,
            'telefono' => $this->Telefono,
            'activo' => (bool) $this->Activo,
            'requiere_activacion' => (bool) $this->Requiere_Cambio_Password,
            'ultimo_acceso' => $this->Ultimo_Acceso,
            'rol' => $vinculoEmpresa ? [
                'id_rol' => $vinculoEmpresa->Id_Rol,
                'nombre_rol' => $vinculoEmpresa->rol->Nombre_Rol,
            ] : null,
            'fecha_creacion' => $this->Fecha_Creacion,
        ];
    }
}
