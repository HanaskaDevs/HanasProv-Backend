<?php

namespace App\Modules\Pedidos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Pedidos\Models\RecepcionImagen;
use App\Modules\Pedidos\Models\RecepcionPedido;
use App\Modules\Pedidos\Models\RecepcionPedidoDetalle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RecepcionPedidoService
{
    protected const DISCO = 'repositorio_proveedores';
    protected const MAX_IMAGENES_POR_LINEA = 3;

    /**
     * Listado interno de pedidos (Sistemas/Admin/Compras): por empresa activa,
     * filtrado por estado (Abierto/Cerrado) y búsqueda libre por número de
     * pedido o razón social del proveedor.
     */
    public function listar(Usuario $usuario, int $idEmpresaActiva, string $estado, ?string $busqueda): Collection
    {
        $this->verificarPermiso($usuario, $idEmpresaActiva);

        return PedidoCompra::where('Id_Empresa', $idEmpresaActiva)
            ->where('Estado', $estado)
            ->where('Activo', 1)
            ->with(['proveedor', 'lineas.recepciones.recepcion'])
            ->when($busqueda, function ($query) use ($busqueda) {
                $query->where(function ($q) use ($busqueda) {
                    $q->where('Nro_Pedido', 'like', "%{$busqueda}%")
                        ->orWhereHas('proveedor', fn ($qp) => $qp->where('Razon_Social', 'like', "%{$busqueda}%"));
                });
            })
            ->orderByDesc('Fecha_Registro_BC')
            ->get();
    }

    public function detalle(Usuario $usuario, int $idEmpresaActiva, int $idPedido): PedidoCompra
    {
        $this->verificarPermiso($usuario, $idEmpresaActiva);

        return $this->obtenerPedido($idPedido, $idEmpresaActiva, [
            'proveedor', 'lineas.recepciones.recepcion.registradoPor', 'lineas.recepciones.imagenes.archivo',
        ]);
    }

    /**
     * Registra un nuevo evento de recepción (una fecha, uno o varias líneas
     * del pedido juntas). El pedido puede tener varios eventos a lo largo
     * del tiempo -> soporta recepciones parciales.
     *
     * $lineas = [
     *   [
     *     'id_detalle_pedido_compra' => int,
     *     'cantidad_recibida' => float,
     *     'recepcion_completa' => bool,
     *     'observacion' => ?string,
     *     'imagenes' => UploadedFile[] (máx 3),
     *   ],
     *   ...
     * ]
     */
    public function registrar(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idPedido,
        string $fechaRecepcion,
        array $lineas
    ): RecepcionPedido {
        $pedido = $this->obtenerPedido($idPedido, $idEmpresaActiva, ['lineas', 'proveedor']);

        $this->verificarPedidoAbierto($pedido);
        $this->verificarLineasPertenecenAlPedido($pedido, $lineas);

        return DB::transaction(function () use ($usuario, $pedido, $fechaRecepcion, $lineas) {
            $recepcion = RecepcionPedido::create([
                'Id_Pedido_Compra' => $pedido->Id_Pedido_Compra,
                'Fecha_Recepcion' => $fechaRecepcion,
                'Registrado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            foreach ($lineas as $linea) {
                $this->crearDetalle($usuario, $pedido, $recepcion, $linea);
            }

            return $recepcion->load('detalles.imagenes.archivo');
        });
    }

    /**
     * Corrige una línea de recepción ya registrada (cantidad, bandera de
     * completa, observación e imágenes). Solo permitido mientras el pedido
     * siga Abierto. Las imágenes nuevas se agregan a las existentes activas,
     * respetando el tope de 3 por línea.
     */
    public function actualizarDetalle(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idRecepcionDetalle,
        array $data
    ): RecepcionPedidoDetalle {
        $detalle = RecepcionPedidoDetalle::with('recepcion.pedido')->findOrFail($idRecepcionDetalle);
        $pedido = $detalle->recepcion->pedido;

        $this->verificarPermiso($usuario, $idEmpresaActiva);

        if ((int) $pedido->Id_Empresa !== $idEmpresaActiva) {
            throw new AccessDeniedHttpException('Este registro no pertenece a la empresa activa.');
        }

        $this->verificarPedidoAbierto($pedido);

        $imagenesNuevas = $data['imagenes'] ?? [];
        $totalImagenesActuales = $detalle->imagenes()->count();

        if ($totalImagenesActuales + count($imagenesNuevas) > self::MAX_IMAGENES_POR_LINEA) {
            throw ValidationException::withMessages([
                'imagenes' => ['Máximo ' . self::MAX_IMAGENES_POR_LINEA . ' imágenes por línea.'],
            ]);
        }

        return DB::transaction(function () use ($usuario, $detalle, $pedido, $data, $imagenesNuevas) {
            $detalle->forceFill([
                'Cantidad_Recibida' => $data['cantidad_recibida'],
                'Recepcion_Completa' => $data['recepcion_completa'],
                'Observacion' => $data['observacion'] ?? null,
                'Modificado_Por' => $usuario->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ])->save();

            foreach ($imagenesNuevas as $imagen) {
                $this->guardarImagen($usuario, $pedido, $detalle, $imagen);
            }

            return $detalle->load('imagenes.archivo');
        });
    }

    /**
     * Cierre manual: el usuario interno decide cerrar el pedido sin importar
     * si todas las cantidades fueron recibidas o no.
     */
    public function cerrarPedido(Usuario $usuario, int $idEmpresaActiva, int $idPedido): PedidoCompra
    {
        $pedido = $this->obtenerPedido($idPedido, $idEmpresaActiva);

        $this->verificarPedidoAbierto($pedido);

        $pedido->forceFill([
            'Estado' => 'Cerrado',
            'Cerrado_Por' => $usuario->Id_Usuario,
            'Fecha_Cierre' => now(),
        ])->save();

        return $pedido;
    }

    protected function crearDetalle(
        Usuario $usuario,
        PedidoCompra $pedido,
        RecepcionPedido $recepcion,
        array $linea
    ): RecepcionPedidoDetalle {
        $imagenes = $linea['imagenes'] ?? [];

        if (count($imagenes) > self::MAX_IMAGENES_POR_LINEA) {
            throw ValidationException::withMessages([
                'imagenes' => ['Máximo ' . self::MAX_IMAGENES_POR_LINEA . ' imágenes por línea.'],
            ]);
        }

        $detalle = RecepcionPedidoDetalle::create([
            'Id_Recepcion_Pedido' => $recepcion->Id_Recepcion_Pedido,
            'Id_Detalle_Pedido_Compra' => $linea['id_detalle_pedido_compra'],
            'Cantidad_Recibida' => $linea['cantidad_recibida'],
            'Recepcion_Completa' => $linea['recepcion_completa'],
            'Observacion' => $linea['observacion'] ?? null,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);

        foreach ($imagenes as $imagen) {
            $this->guardarImagen($usuario, $pedido, $detalle, $imagen);
        }

        return $detalle;
    }

    protected function guardarImagen(
        Usuario $usuario,
        PedidoCompra $pedido,
        RecepcionPedidoDetalle $detalle,
        UploadedFile $imagen
    ): RecepcionImagen {
        $registroArchivo = Archivo::create([
            'Id_Proveedor' => $pedido->Id_Proveedor,
            'Nombre_Original' => $imagen->getClientOriginalName(),
            'Ruta_Almacenamiento' => '',
            'Hash_Archivo' => hash_file('sha256', $imagen->getRealPath()),
            'Tipo_Mime' => $imagen->getMimeType(),
            'Tamano_Bytes' => $imagen->getSize(),
            'Categoria_Archivo' => 'recepciones',
            'Id_Usuario_Carga' => $usuario->Id_Usuario,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        $extension = $imagen->getClientOriginalExtension();
        $carpeta = "{$pedido->Id_Empresa}/{$pedido->Id_Proveedor}/pedidos/{$pedido->Id_Pedido_Compra}/recepciones/{$detalle->Id_Recepcion_Pedido_Detalle}";
        $nombreFisico = "{$registroArchivo->Id_Archivo}.{$extension}";

        Storage::disk(self::DISCO)->putFileAs($carpeta, $imagen, $nombreFisico);

        $registroArchivo->update(['Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}"]);

        return RecepcionImagen::create([
            'Id_Recepcion_Pedido_Detalle' => $detalle->Id_Recepcion_Pedido_Detalle,
            'Id_Archivo' => $registroArchivo->Id_Archivo,
            'Activo' => 1,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function verImagen(Usuario $usuario, int $idEmpresaActiva, int $idRecepcionImagen)
    {
        $this->verificarPermiso($usuario, $idEmpresaActiva);

        $imagen = RecepcionImagen::with('archivo')->findOrFail($idRecepcionImagen);
        $rutaCompleta = Storage::disk(self::DISCO)->path($imagen->archivo->Ruta_Almacenamiento);

        if (! is_file($rutaCompleta)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
        }

        return response()->file($rutaCompleta, [
            'Content-Type' => $imagen->archivo->Tipo_Mime,
            'Content-Disposition' => 'inline; filename="' . $imagen->archivo->Nombre_Original . '"',
        ]);
    }

    protected function verificarPermiso(Usuario $usuario, int $idEmpresaActiva): void
    {
        if (! $usuario->puedeGestionarRecepciones($idEmpresaActiva)) {
            throw new AccessDeniedHttpException('No tiene permisos para gestionar recepciones de pedidos.');
        }
    }

    protected function verificarPedidoAbierto(PedidoCompra $pedido): void
    {
        if ($pedido->Estado === 'Cerrado') {
            throw new AccessDeniedHttpException('Este pedido ya está cerrado y no admite más cambios.');
        }
    }

    protected function verificarLineasPertenecenAlPedido(PedidoCompra $pedido, array $lineas): void
    {
        $idsValidas = $pedido->lineas->pluck('Id_Detalle_Pedido_Compra')->all();

        foreach ($lineas as $linea) {
            if (! in_array((int) $linea['id_detalle_pedido_compra'], $idsValidas, true)) {
                throw ValidationException::withMessages([
                    'lineas' => ['Una de las líneas enviadas no pertenece a este pedido.'],
                ]);
            }
        }
    }

    protected function obtenerPedido(int $idPedido, int $idEmpresaActiva, array $with = []): PedidoCompra
    {
        return PedidoCompra::where('Id_Empresa', $idEmpresaActiva)
            ->with($with)
            ->findOrFail($idPedido);
    }
}