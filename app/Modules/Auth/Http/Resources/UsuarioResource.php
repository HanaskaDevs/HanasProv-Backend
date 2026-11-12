<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->Id_Usuario,
            'email' => $this->Email,
            'nombre_completo' => $this->Nombre_Completo,
            'cargo' => $this->Cargo,
            'tipo_usuario' => $this->Tipo_Usuario,
            'requiere_cambio_password' => (bool) $this->Requiere_Cambio_Password,
            /*
             * ¿Es la persona que resuelve las solicitudes de arribo
             * tardío? (12-nov-2026, pedido del usuario: "al usuario
             * vacurio@hanaska.com que le salga el apartado aprobaciones
             * de arribo").
             *
             * La regla vive en el modelo (Usuario::
             * resuelveAprobacionesArribo: su correo es el de
             * portal.email_aprobacion_arribo) porque la comparten el
             * menú y el permiso del backend
             * (HorarioEntregaService::verificarAccesoResolverAprobacion).
             * Acá solo se expone al frontend para DIBUJAR el ítem.
             */
            'resuelve_aprobaciones_arribo' => $this->resource->resuelveAprobacionesArribo(),
            'empresas' => EmpresaAccesoResource::collection($this->whenLoaded('empresas')),
        ];
    }
}
