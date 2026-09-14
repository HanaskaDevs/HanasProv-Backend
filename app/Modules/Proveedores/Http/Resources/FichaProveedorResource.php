<?php

namespace App\Modules\Proveedores\Http\Resources;

use App\Modules\Proveedores\Services\FichaProveedorService;
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

            // Calificación CAMPO POR CAMPO: un objeto { nombre_campo:
            // {estado, observacion, fecha} }, más fácil de consumir en el
            // front que un array. Y el estado general, derivado de todas
            // las calificaciones (ver Proveedor::estadoGeneralCalificacionFicha).
            'calificaciones_campos' => $this->whenLoaded(
                'calificacionesCampos',
                fn () => $this->calificacionesCampos->mapWithKeys(fn ($c) => [
                    $c->Nombre_Campo => [
                        'estado' => $c->Estado,
                        'observacion' => $c->Comentario,
                        'fecha' => $c->Fecha_Calificacion?->toIso8601String(),
                    ],
                ])
            ),
            'estado_calificacion_general' => $this->whenLoaded(
                'calificacionesCampos',
                fn () => $this->estadoGeneralCalificacionFicha()
            ),

            /**
             * Lo calcula el BACKEND y la pantalla solo lo consume.
             *
             * Antes cada lado tenía su propia versión de "¿está completa?",
             * y las dos miraban solo el RUC y la razón social. Cuando la
             * activación de la cuenta empezó a pedir esos dos datos por
             * adelantado, ambas dieron la sección por completa desde el
             * primer día y la ficha aparecía al 100% y bloqueada estando
             * vacía. Con un solo cálculo, del lado que además valida el
             * formulario, no pueden volver a discrepar.
             */
            'seccion_1_completa' => FichaProveedorService::seccion1EstaCompleta($this->resource),

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