<?php

namespace App\Modules\Proveedores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FichaProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_proveedor' => $this->Id_Proveedor,
            'seccion_actual' => $this->Seccion_Actual,
            'porcentaje_completado' => $this->Porcentaje_Completado_Ficha,
            'estado' => $this->whenLoaded('estado', fn () => $this->estado?->Nombre_Estado),

            // 100 si Aprobado, 0 si Rechazado, null si todavía no se calificó.
            'calificacion_ficha' => [
                'estado' => $this->Estado_Calificacion_Ficha,
                'puntaje' => match ($this->Estado_Calificacion_Ficha) {
                    'Aprobado' => 100,
                    'Rechazado' => 0,
                    default => null,
                },
                'observacion' => $this->Comentario_Calificacion_Ficha,
                'fecha' => $this->Fecha_Calificacion_Ficha?->toIso8601String(),
            ],

            'seccion_1' => [
                'ruc' => $this->Ruc,
                'clase_contribuyente' => $this->Clase_Contribuyente,
                'razon_social' => $this->Razon_Social,
                'nombre_comercial' => $this->Nombre_Comercial,
                'email' => $this->Email,
                'telefono' => $this->Telefono,
                'direccion' => $this->Direccion,
                'ciudad' => $this->Ciudad,
                'pagina_web' => $this->Pagina_Web,
                'latitud' => $this->Latitud,
                'longitud' => $this->Longitud,
                'representante_legal' => $this->Representante_Legal,
                'correo_representante' => $this->Correo_Representante,
                'telefono_representante' => $this->Telefono_Representante,
                'contacto_venta' => $this->Contacto_Venta,
                'correo_venta' => $this->Correo_Venta,
                'telefono_contacto_venta' => $this->Telefono_Contacto_Venta,
                'contacto_calidad' => $this->Contacto_Calidad,
                'correo_calidad' => $this->Correo_Calidad,
                'telefono_contacto_calidad' => $this->Telefono_Contacto_Calidad,
                'contacto_contabilidad' => $this->Contacto_Contabilidad,
                'correo_contabilidad' => $this->Correo_Contabilidad,
                'telefono_contabilidad' => $this->Telefono_Contabilidad,
            ],

            'seccion_2' => [
                'clases' => $this->whenLoaded('clases', fn () => $this->clases->map(fn ($c) => [
                    'id_clase_proveedor' => $c->Id_Clase_Proveedor,
                    'nombre_clase' => $c->Nombre_Clase,
                ])),
            ],

            'seccion_3' => [
                'categorias' => $this->whenLoaded('categoriasProducto', fn () => $this->categoriasProducto->map(fn ($c) => [
                    'id_categoria_producto' => $c->Id_Categoria_Producto,
                    'nombre_categoria' => $c->Nombre_Categoria,
                ])),
            ],
        ];
    }
}