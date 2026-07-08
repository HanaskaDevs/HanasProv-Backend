<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Ficha_Productos\Models\DocumentoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductoService
{
    protected const DISCO = 'repositorio_proveedores';

    public function listar(Usuario $usuario)
    {
        return $this->miProveedor($usuario)
            ->productos()
            ->where('Activo', 1)
            ->with(['unidadPresentacion', 'documentos.tipoDocumento', 'documentos.archivo'])
            ->get();
    }

    public function crear(Usuario $usuario, array $data): Producto
    {
        $proveedor = $this->miProveedor($usuario);

        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $data['id_unidad_presentacion'],
            'Nombre_Producto' => $data['nombre_producto'],
            'Codigo_Barras' => $data['codigo_barras'] ?? null,
            'Precio' => $data['precio'],
            'Activo' => 1,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function subirDocumento(
        Usuario $usuario,
        int $idProducto,
        int $idTipoDocumentoProducto,
        UploadedFile $archivo
    ): DocumentoProducto {
        $producto = $this->miProducto($usuario, $idProducto);
        $tipo = TipoDocumentoProducto::where('Activo', 1)->findOrFail($idTipoDocumentoProducto);

        return DB::transaction(function () use ($usuario, $producto, $tipo, $archivo) {
            $registroArchivo = Archivo::create([
                'Id_Proveedor' => $producto->Id_Proveedor,
                'Nombre_Original' => $archivo->getClientOriginalName(),
                'Ruta_Almacenamiento' => '',
                'Hash_Archivo' => hash_file('sha256', $archivo->getRealPath()),
                'Tipo_Mime' => $archivo->getMimeType(),
                'Tamano_Bytes' => $archivo->getSize(),
                'Categoria_Archivo' => $tipo->Carpeta_Slug,
                'Id_Usuario_Carga' => $usuario->Id_Usuario,
                'Fecha_Carga' => now(),
                'Activo' => 1,
            ]);

            $extension = $archivo->getClientOriginalExtension();
            $carpeta = "{$producto->proveedor->Id_Empresa}/{$producto->Id_Proveedor}/productos/{$producto->Id_Producto}/{$tipo->Carpeta_Slug}";
            $nombreFisico = "{$registroArchivo->Id_Archivo}.{$extension}";

            Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombreFisico);

            $registroArchivo->update(['Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}"]);

            DocumentoProducto::where('Id_Producto', $producto->Id_Producto)
                ->where('Id_Tipo_Documento_Producto', $tipo->Id_Tipo_Documento_Producto)
                ->where('Activo', 1)
                ->update(['Activo' => 0]);

            return DocumentoProducto::create([
                'Id_Producto' => $producto->Id_Producto,
                'Id_Tipo_Documento_Producto' => $tipo->Id_Tipo_Documento_Producto,
                'Id_Archivo' => $registroArchivo->Id_Archivo,
                'Activo' => 1,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ])->load('archivo', 'tipoDocumento');
        });
    }

    protected function miProveedor(Usuario $usuario): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) gestionan su ficha de productos.');
        }

        if (! $usuario->Id_Proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado.');
        }

        return Proveedor::findOrFail($usuario->Id_Proveedor);
    }

    protected function miProducto(Usuario $usuario, int $idProducto): Producto
    {
        $proveedor = $this->miProveedor($usuario);

        return Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->with('proveedor')
            ->findOrFail($idProducto);
    }
}