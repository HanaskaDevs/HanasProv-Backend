<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Ficha_Productos\Models\DocumentoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductoService
{
    protected const DISCO = 'repositorio_proveedores';

    /**
     * Mapeo de código de unidad de medida en BC -> Nombre_Unidad exacto en
     * nuestra tabla local Unidad_Presentacion. Un código vacío/no reconocido
     * en BC siempre cae a "UN" (Unidad), como se definió con el negocio.
     */
    protected const MAPA_UNIDAD_BC = [
        'UN' => 'Unidad',
        'KG' => 'Kilogramo',
        'PK' => 'Paquete',
    ];

    public function listar(Usuario $usuario, int $idEmpresaActiva)
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $this->sincronizarDesdeBC($proveedor, $idEmpresaActiva);

        return $proveedor
            ->productos()
            ->where('Activo', 1)
            ->with(['unidadPresentacion', 'documentos.tipoDocumento', 'documentos.archivo'])
            ->get();
    }

    public function crear(Usuario $usuario, int $idEmpresaActiva, array $data): Producto
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $this->verificarNoBloqueado($proveedor);

        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $data['id_unidad_presentacion'],
            // Homogeneizar mayúsculas sin importar cómo lo escriba el proveedor.
            'Nombre_Producto' => mb_strtoupper($data['nombre_producto'], 'UTF-8'),
            'Codigo_Barras' => $data['codigo_barras'] ?? null,
            'Precio' => $data['precio'] ?? null,
            'Activo' => 1,
            'Bloqueado' => 0,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    /**
     * Elimina un producto completo (borrado físico, ya que aún no ha sido
     * enviado a calificación -> no requiere trazabilidad histórica). Solo
     * permitido mientras el proveedor NO esté bloqueado (envío pendiente).
     */
    public function eliminar(Usuario $usuario, int $idEmpresaActiva, int $idProducto): void
    {
        $producto = $this->miProducto($usuario, $idEmpresaActiva, $idProducto);

        if ($producto->Bloqueado) {
            throw new AccessDeniedHttpException('No puede eliminar un producto mientras está en revisión.');
        }

        DB::transaction(function () use ($producto) {
            foreach ($producto->documentos as $documento) {
                $archivo = $documento->archivo;
                $documento->delete();
                $this->eliminarArchivoFisico($archivo);
            }

            $producto->delete();
        });
    }

    /**
     * Elimina varios productos completos de una sola vez (checkboxes en el
     * frontend). Se salta silenciosamente cualquier id que no pertenezca al
     * proveedor o que esté bloqueado, y retorna cuántos sí se eliminaron.
     */
    public function eliminarMasivo(Usuario $usuario, int $idEmpresaActiva, array $idsProductos): int
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ($this->tieneProductosBloqueados($proveedor)) {
            throw new AccessDeniedHttpException('No puede eliminar productos mientras tiene un envío en revisión.');
        }

        $productos = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->whereIn('Id_Producto', $idsProductos)
            ->where('Bloqueado', 0)
            ->with('documentos.archivo')
            ->get();

        $total = 0;

        DB::transaction(function () use ($productos, &$total) {
            foreach ($productos as $producto) {
                foreach ($producto->documentos as $documento) {
                    $archivo = $documento->archivo;
                    $documento->delete();
                    $this->eliminarArchivoFisico($archivo);
                }
                $producto->delete();
                $total++;
            }
        });

        return $total;
    }
    /**
     * Elimina un documento individual de un producto (ej. reemplazar por uno
     * nuevo desde cero, o quitarlo si ya no aplica). Solo mientras el
     * producto no esté bloqueado.
     */
    public function eliminarDocumento(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProducto): void
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $documento = DocumentoProducto::whereHas('producto', fn($q) => $q->where('Id_Proveedor', $proveedor->Id_Proveedor))
            ->with('archivo', 'producto')
            ->findOrFail($idDocumentoProducto);

        if ($documento->producto->Bloqueado) {
            throw new AccessDeniedHttpException('No puede eliminar documentos mientras el producto está en revisión.');
        }

        DB::transaction(function () use ($documento) {
            $archivo = $documento->archivo;
            $documento->delete();
            $this->eliminarArchivoFisico($archivo);
        });
    }

    protected function eliminarArchivoFisico(?Archivo $archivo): void
    {
        if (! $archivo) {
            return;
        }

        $ruta = $archivo->Ruta_Almacenamiento;

        if ($ruta && Storage::disk(self::DISCO)->exists($ruta)) {
            Storage::disk(self::DISCO)->delete($ruta);
        }

        $archivo->delete();
    }

    public function subirDocumento(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idProducto,
        int $idTipoDocumentoProducto,
        UploadedFile $archivo
    ): DocumentoProducto {
        $producto = $this->miProducto($usuario, $idEmpresaActiva, $idProducto);

        if ($producto->Bloqueado) {
            throw new AccessDeniedHttpException('Este producto está bloqueado mientras se encuentra en revisión y no puede modificarse.');
        }

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

    /**
     * Sincronización automática al entrar a Ficha Productos: busca en
     * BC_Producto_Proveedor los productos de este proveedor (por Empresa +
     * Nro_Proveedor, este último obtenido vía BC_Ficha_Proveedor.Ruc), y crea
     * localmente los que todavía no existan (identificados por
     * Bc_Nro_Producto). La descripción viene de BC_Ficha_Producto.
     *
     * Se omite por completo si el proveedor tiene un envío pendiente de
     * calificación (Bloqueado), para no interferir con esa revisión.
     */
    protected function sincronizarDesdeBC(Proveedor $proveedor, int $idEmpresaActiva): void
    {
        if ($this->tieneProductosBloqueados($proveedor)) {
            return;
        }

        try {
            $empresa = $proveedor->empresa;

            if (! $empresa || ! $empresa->Empresa_BC) {
                return;
            }

            $empresaBc = trim($empresa->Empresa_BC);

            $nroProveedorBc = DB::table('BC_Ficha_Proveedor')
                ->where('Empresa', $empresaBc)
                ->where('Nro_Identificacion', $proveedor->Ruc)
                ->value('Nro_Proveedor');

            if (! $nroProveedorBc) {
                return;
            }

            $productosBc = DB::table('BC_Producto_Proveedor')
                ->where('Empresa', $empresaBc)
                ->where('Nro_Proveedor', $nroProveedorBc)
                ->get();

            if ($productosBc->isEmpty()) {
                return;
            }

            $codigosExistentes = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->whereNotNull('Bc_Nro_Producto')
                ->pluck('Bc_Nro_Producto')
                ->all();

            foreach ($productosBc as $productoBc) {
                if (in_array($productoBc->Nro_Producto, $codigosExistentes, true)) {
                    continue;
                }

                $fichaProducto = DB::table('BC_Ficha_Producto')
                    ->where('Empresa', $empresaBc)
                    ->where('Nro_Producto', $productoBc->Nro_Producto)
                    ->first();

                $descripcion = $fichaProducto->Descripcion ?? $productoBc->Nro_Producto;
                $gtin = $fichaProducto->GTIN ?? null;

                $idUnidad = $this->resolverUnidadPresentacion($productoBc->Cod_Unidad_Medida);

                Producto::create([
                    'Id_Proveedor' => $proveedor->Id_Proveedor,
                    'Id_Unidad_Presentacion' => $idUnidad,
                    'Nombre_Producto' => mb_strtoupper($descripcion, 'UTF-8'),
                    'Codigo_Barras' => $gtin ?: null,
                    'Precio' => $productoBc->Costo_Unitario_Directo,
                    'Bc_Nro_Producto' => $productoBc->Nro_Producto,
                    'Activo' => 1,
                    'Bloqueado' => 0,
                    'Fecha_Creacion' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Nunca debe romper la carga de la página por un problema en la
            // sincronización -> se registra el error y se sigue mostrando
            // los productos que ya existan localmente.
            Log::error('Error sincronizando productos desde BC', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolverUnidadPresentacion(?string $codUnidadBc): int
    {
        $codigo = strtoupper(trim((string) $codUnidadBc));

        if ($codigo === '' || ! isset(self::MAPA_UNIDAD_BC[$codigo])) {
            $codigo = 'UN';
        }

        $nombreBuscado = self::MAPA_UNIDAD_BC[$codigo];

        return UnidadPresentacion::where('Nombre_Unidad', $nombreBuscado)->value('Id_Unidad_Presentacion')
            ?? UnidadPresentacion::where('Nombre_Unidad', 'Unidad')->value('Id_Unidad_Presentacion');
    }

    public function resumenRegistro(Usuario $usuario, int $idEmpresaActiva): array
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $productos = $proveedor->productos()
            ->where('Activo', 1)
            ->with('documentos.tipoDocumento')
            ->get();

        $tiposObligatorios = TipoDocumentoProducto::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->pluck('Id_Tipo_Documento_Producto');

        $productosIncompletos = [];

        foreach ($productos as $producto) {
            $tiposSubidos = $producto->documentos
                ->where('Activo', true)
                ->pluck('Id_Tipo_Documento_Producto');

            $faltantes = $tiposObligatorios->diff($tiposSubidos);

            if ($faltantes->isNotEmpty()) {
                $productosIncompletos[] = $producto->Nombre_Producto;
            }
        }

        return [
            'total_productos' => $productos->count(),
            'productos_incompletos' => $productosIncompletos,
            'puede_registrar' => $productos->count() > 0
                && empty($productosIncompletos)
                && ! $this->tieneProductosBloqueados($proveedor),
            'ya_bloqueado' => $this->tieneProductosBloqueados($proveedor),
        ];
    }

    public function registrar(Usuario $usuario, int $idEmpresaActiva): int
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ($this->tieneProductosBloqueados($proveedor)) {
            throw ValidationException::withMessages([
                'productos' => ['Ya existe un envío pendiente de calificación.'],
            ]);
        }

        $productos = $proveedor->productos()
            ->where('Activo', 1)
            ->with('documentos.tipoDocumento')
            ->get();

        if ($productos->isEmpty()) {
            throw ValidationException::withMessages([
                'productos' => ['No tienes productos para registrar.'],
            ]);
        }

        $tiposObligatorios = TipoDocumentoProducto::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->pluck('Id_Tipo_Documento_Producto');

        foreach ($productos as $producto) {
            $tiposSubidos = $producto->documentos->where('Activo', true)->pluck('Id_Tipo_Documento_Producto');
            $faltantes = $tiposObligatorios->diff($tiposSubidos);

            if ($faltantes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'productos' => ["El producto \"{$producto->Nombre_Producto}\" no tiene todos los documentos obligatorios."],
                ]);
            }
        }

        Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->update([
                'Bloqueado' => 1,
                'Estado_Calificacion' => 'Pendiente',
                'Comentario_Calificacion' => null,
                'Calificado_Por' => null,
                'Fecha_Calificacion' => null,
            ]);

        return $productos->count();
    }

    protected function tieneProductosBloqueados(Proveedor $proveedor): bool
    {
        return Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Bloqueado', 1)
            ->exists();
    }

    protected function verificarNoBloqueado(Proveedor $proveedor): void
    {
        if ($this->tieneProductosBloqueados($proveedor)) {
            throw new AccessDeniedHttpException('Tienes productos en revisión. No puedes agregar nuevos productos hasta que sean calificados.');
        }
    }

    protected function miProveedor(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) gestionan su ficha de productos.');
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario no tiene un Proveedor asociado a la empresa activa.');
        }

        return $proveedor;
    }

    protected function miProducto(Usuario $usuario, int $idEmpresaActiva, int $idProducto): Producto
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        return Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->with('proveedor')
            ->findOrFail($idProducto);
    }

    public function descargarDocumento(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProducto)
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $documento = DocumentoProducto::whereHas('producto', fn($q) => $q->where('Id_Proveedor', $proveedor->Id_Proveedor))
            ->with('archivo')
            ->findOrFail($idDocumentoProducto);

        $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

        if (! is_file($rutaCompleta)) {
            throw new NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
        }

        return response()->file($rutaCompleta, [
            'Content-Type' => $documento->archivo->Tipo_Mime,
            'Content-Disposition' => 'inline; filename="' . $documento->archivo->Nombre_Original . '"',
        ]);
    }
}
