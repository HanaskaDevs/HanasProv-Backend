<?php

namespace App\Modules\Empresas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_empresa' => $this->Id_Empresa,
            'razon_social' => $this->Razon_Social,
            'ruc' => $this->Ruc,
            'nombre_comercial' => $this->Nombre_Comercial,
            'logo_url' => $this->Logo_Url,
            'empresa_bc' => $this->Empresa_BC,
            'activo' => $this->Activo,
        ];
    }
}