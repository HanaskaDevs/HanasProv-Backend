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
             * Sale de comparar su correo con el que ya recibe esos
             * avisos (portal.email_aprobacion_arribo) en vez de
             * escribir el correo en el frontend: así el día que cambie
             * la persona, se cambia UNA variable de entorno y se mueven
             * juntos el correo y el ítem del menú.
             *
             * Es solo para DIBUJAR el menú. Quién puede aprobar de
             * verdad lo sigue decidiendo el backend por rol (ver
             * HorarioEntregaService::verificarAccesoResolverAprobacion).
             */
            'resuelve_aprobaciones_arribo' => $this->resuelveAprobacionesArribo(),
            'empresas' => EmpresaAccesoResource::collection($this->whenLoaded('empresas')),
        ];
    }

    /** Comparación sin distinguir mayúsculas ni espacios de más. */
    protected function resuelveAprobacionesArribo(): bool
    {
        $configurado = trim((string) config('portal.email_aprobacion_arribo'));

        if ($configurado === '') {
            return false;
        }

        return strcasecmp(trim((string) $this->Email), $configurado) === 0;
    }
}
