<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Ficha_Productos\Models\DocumentoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\Proveedor;
use App\Shared\MueveArchivoAHistorico;
use App\Shared\SaneadorNombreArchivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductoService
{
    use MueveArchivoAHistorico;

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

    /**
     * Paginado del lado del servidor -> con catálogos grandes (1000+
     * productos) traer todo de una y paginar/filtrar en el navegador
     * sería un desastre de performance (payload enorme + el browser
     * renderizando/filtrando miles de filas). Acá se pagina y se busca
     * directo en la consulta SQL, así el front nunca recibe más de
     * $porPagina productos por request.
     *
     * La sincronización con BC solo corre en la página 1 sin búsqueda
     * activa -> es el "punto de entrada natural" a la pantalla, y evita
     * ejecutar esa sincronización (que pega contra BC_Producto_Proveedor)
     * en cada cambio de página o de término de búsqueda.
     */
    public function listar(Usuario $usuario, int $idEmpresaActiva, ?string $busqueda = null, int $pagina = 1, int $porPagina = 20)
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ($pagina === 1 && ! $busqueda) {
            $this->sincronizarDesdeBC($proveedor, $idEmpresaActiva);
        }

        return $proveedor
            ->productos()
            ->where('Activo', 1)
            ->when($busqueda, function ($query) use ($busqueda) {
                $query->where(function ($q) use ($busqueda) {
                    $q->where('Nombre_Producto', 'like', "%{$busqueda}%")
                        ->orWhere('Codigo_Barras', 'like', "%{$busqueda}%");
                });
            })
            ->with(['unidadPresentacion', 'documentos.tipoDocumento', 'documentos.archivo'])
            ->orderBy('Nombre_Producto')
            ->paginate($porPagina, ['*'], 'page', $pagina);
    }

    public function crear(Usuario $usuario, int $idEmpresaActiva, array $data): Producto
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        // Ya no se bloquea acá: un producto NUEVO no tiene nada que ver
        // con otros productos que estén en revisión -> nace con
        // Bloqueado=0, editable, sin importar cuántos lotes tenga el
        // proveedor pendientes de calificación en paralelo.

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
     * proveedor o que esté bloqueado (en revisión), y retorna cuántos sí
     * se eliminaron -> no hace falta que el proveedor esté "libre de todo
     * bloqueo": alcanza con que ESOS productos puntuales no lo estén.
     */
    public function eliminarMasivo(Usuario $usuario, int $idEmpresaActiva, array $idsProductos): int
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

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
            ->with('archivo', 'producto.proveedor')
            ->findOrFail($idDocumentoProducto);

        $producto = $documento->producto;

        $puedeEditarAunqueBloqueado = $producto->proveedor->Correcciones_Pendientes_Productos
            && $producto->Estado_Calificacion === 'Rechazado';

        if ($producto->Bloqueado && ! $puedeEditarAunqueBloqueado) {
            throw new AccessDeniedHttpException('No puede eliminar documentos mientras el producto está en revisión.');
        }

        DB::transaction(function () use ($documento, $producto) {
            $rutaHistorico = $this->archivarOEliminarSegunEstadoProducto(
                self::DISCO,
                $documento->archivo?->Ruta_Almacenamiento,
                $producto->Estado_Calificacion,
                $documento->Fecha_Creacion,
                $producto->Fecha_Calificacion
            );

            $documento->delete();

            // Si NO se archivó a histórico (o sea, se borró el físico
            // directo), el registro Archivo tampoco tiene sentido
            // dejarlo -> nada más lo referencia.
            if (! $rutaHistorico && $documento->archivo) {
                $documento->archivo->delete();
            }
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

        // Bloqueado normalmente impide tocar el producto -> EXCEPTO
        // mientras haya correcciones pendientes de confirmar Y este
        // producto puntual esté específicamente Rechazado (no alcanza
        // con "no Aprobado": un producto recién enviado y nunca
        // revisado también queda en Estado_Calificacion="Pendiente", y
        // ESE sí debe seguir bloqueado -> por eso el chequeo exige
        // Rechazado exacto, no "distinto de Aprobado").
        $puedeEditarAunqueBloqueado = $producto->proveedor->Correcciones_Pendientes_Productos
            && $producto->Estado_Calificacion === 'Rechazado';

        if ($producto->Bloqueado && ! $puedeEditarAunqueBloqueado) {
            throw new AccessDeniedHttpException('Este producto está bloqueado mientras se encuentra en revisión y no puede modificarse.');
        }

        $tipo = TipoDocumentoProducto::where('Activo', 1)->findOrFail($idTipoDocumentoProducto);

        return DB::transaction(function () use ($usuario, $producto, $tipo, $archivo) {
            $registroArchivo = Archivo::create([
                'Id_Proveedor' => $producto->Id_Proveedor,
                // Nombre_Original arranca vacío -> se completa más abajo
                // con el mismo nombre que se usa físicamente en disco
                // (una vez que se conoce Id_Archivo, necesario para
                // armarlo). Antes acá se generaba un nombre DISTINTO al
                // que terminaba en el repositorio -> el front mostraba
                // una cosa y el archivo real en disco se llamaba otra,
                // lo cual confunde si alguien tiene que buscarlo a mano.
                'Nombre_Original' => '',
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
            // /var/repositorio/proveedores/{RUC}/{Id_Empresa}_{Empresa}/doc_productos/{IdProducto}_{Producto}/{tipo}/{CODIGO}_{IdProducto}_{RazonSocial}_{IdArchivo}.pdf
            // Subcarpeta por producto -> no pisar archivos de otro
            // producto con un documento del mismo tipo. Subcarpeta por
            // empresa -> el mismo RUC puede estar registrado como
            // proveedor en más de una empresa a la vez.
            $ruc = SaneadorNombreArchivo::sanear($producto->proveedor->Ruc, "sin-ruc-{$producto->Id_Proveedor}");
            $nombreEmpresa = SaneadorNombreArchivo::sanear(
                $producto->proveedor->empresa?->Nombre_Comercial ?? $producto->proveedor->empresa?->Razon_Social
            );
            $nombreProducto = SaneadorNombreArchivo::sanear($producto->Nombre_Producto, "producto-{$producto->Id_Producto}");
            $codigo = SaneadorNombreArchivo::sanear($tipo->Codigo_Archivo ?? $tipo->Carpeta_Slug, 'DOC');
            $razonSocial = SaneadorNombreArchivo::sanear($producto->proveedor->Razon_Social);

            $carpeta = "proveedores/{$ruc}/{$producto->proveedor->Id_Empresa}_{$nombreEmpresa}/doc_productos/"
                ."{$producto->Id_Producto}_{$nombreProducto}/{$tipo->Carpeta_Slug}";
            $nombreFisico = "{$codigo}_{$producto->Id_Producto}_{$razonSocial}_{$registroArchivo->Id_Archivo}.{$extension}";

            Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombreFisico);

            $registroArchivo->update([
                'Nombre_Original' => $nombreFisico,
                'Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}",
            ]);

            // El(los) documento(s) anteriores de este mismo tipo se
            // desactivan. El archivo físico viejo se archiva en
            // "historico/" SOLO si de verdad fue el que un admin vio y
            // rechazó (comparando su fecha de creación contra la fecha
            // de calificación del producto) -> si es un archivo subido
            // durante esta misma corrección, que nadie revisó todavía,
            // se borra directo. Ver MueveArchivoAHistorico para el
            // detalle de por qué hace falta esta comparación acá.
            $fechaCalificacionProducto = $producto->Fecha_Calificacion;

            $documentosViejos = DocumentoProducto::where('Id_Producto', $producto->Id_Producto)
                ->where('Id_Tipo_Documento_Producto', $tipo->Id_Tipo_Documento_Producto)
                ->where('Activo', 1)
                ->with('archivo')
                ->get();

            foreach ($documentosViejos as $documentoViejo) {
                $rutaHistorico = $this->archivarOEliminarSegunEstadoProducto(
                    self::DISCO,
                    $documentoViejo->archivo?->Ruta_Almacenamiento,
                    $producto->Estado_Calificacion,
                    $documentoViejo->Fecha_Creacion,
                    $fechaCalificacionProducto
                );

                if ($rutaHistorico) {
                    $documentoViejo->archivo->update(['Ruta_Almacenamiento' => $rutaHistorico]);
                }
            }

            DocumentoProducto::where('Id_Producto', $producto->Id_Producto)
                ->where('Id_Tipo_Documento_Producto', $tipo->Id_Tipo_Documento_Producto)
                ->where('Activo', 1)
                ->update(['Activo' => 0]);

            $nuevoDocumento = DocumentoProducto::create([
                'Id_Producto' => $producto->Id_Producto,
                'Id_Tipo_Documento_Producto' => $tipo->Id_Tipo_Documento_Producto,
                'Id_Archivo' => $registroArchivo->Id_Archivo,
                'Activo' => 1,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            // OJO: acá NO se resetea el producto a "Pendiente" todavía
            // -> se queda "Rechazado" (con su motivo a la vista) hasta
            // que el proveedor confirme con "Registrar productos
            // actualizados". Así puede corregir varios documentos del
            // mismo producto sin que desaparezca el motivo del rechazo
            // ni se re-bloquee después del primer archivo que toque
            // (ver confirmarCorrecciones, que sí hace ese reseteo en
            // bloque al final).

            return $nuevoDocumento->load('archivo', 'tipoDocumento');
        });
    }

    /**
     * Sincronización automática al entrar a Ficha Productos: busca en
     * BC_Producto_Proveedor los productos de este proveedor (por Empresa +
     * Nro_Proveedor, este último obtenido vía BC_Ficha_Proveedor.Ruc), y crea
     * localmente los que todavía no existan (identificados por
     * Bc_Nro_Producto). La descripción viene de BC_Ficha_Producto.
     *
     * Ya NO se omite si hay productos bloqueados: como se permiten varios
     * envíos en paralelo, y esto solo CREA productos nuevos (nunca toca
     * uno existente), no hay riesgo de interferir con una revisión en
     * curso.
     */
    protected function sincronizarDesdeBC(Proveedor $proveedor, int $idEmpresaActiva): void
    {
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

    /**
     * $idsProductos: si viene, el resumen (total, incompletos, etc.) se
     * calcula SOLO sobre esos productos -> lo usa el modal de confirmar
     * registro, acotado a lo que el proveedor tildó en la lista. Si no
     * viene (null), se calcula sobre todos los activos, como antes (lo
     * sigue usando la franja superior para saber si hay algo bloqueado,
     * algo que no depende de cuáles estén seleccionados).
     */
    public function resumenRegistro(Usuario $usuario, int $idEmpresaActiva, ?array $idsProductos = null): array
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $productos = $proveedor->productos()
            ->where('Activo', 1)
            ->when($idsProductos !== null, fn ($query) => $query->whereIn('Id_Producto', $idsProductos))
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
            // Ya no depende de si hay OTROS productos bloqueados -> se
            // permiten varios envíos en paralelo, cada uno con lo suyo.
            'puede_registrar' => $productos->count() > 0 && empty($productosIncompletos),
            // Conteo (no un booleano global) -> más útil para mostrar
            // "tienes N en revisión" en vez de un bloqueo de todo o nada.
            'productos_en_revision' => Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->where('Bloqueado', 1)
                ->count(),
            // Conteos totales del catálogo completo (no solo lo que
            // trajo $productos, que puede estar acotado a $idsProductos)
            // -> los usan pantallas de resumen (ej. Calificación) que
            // necesitan el panorama completo sin pedir todo el catálogo
            // paginado solo para contar.
            'productos_totales_catalogo' => Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->count(),
            'productos_aprobados' => Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->where('Estado_Calificacion', 'Aprobado')
                ->count(),
            'productos_rechazados' => Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->where('Estado_Calificacion', 'Rechazado')
                ->count(),
            // true = hay (o hubo) productos rechazados que el proveedor
            // todavía no confirmó haber corregido -> mientras esté en
            // true, esos productos puntuales quedan editables aunque
            // sigan Bloqueado. Se apaga con "Registrar productos
            // actualizados" (ver confirmarCorrecciones).
            'correcciones_pendientes' => (bool) $proveedor->Correcciones_Pendientes_Productos,
        ];
    }

    /**
     * Registra (bloquea para calificación) SOLO los productos indicados
     * en $idsProductos -> el resto de los productos activos del
     * proveedor quedan intactos, editables, para seguir cargándolos y
     * mandarlos en un envío posterior. SÍ SE PERMITEN varios envíos en
     * paralelo (ya no se exige que no haya ningún otro lote pendiente):
     * cada producto tiene su propio Bloqueado, no hay un bloqueo único
     * por proveedor.
     */
    public function registrar(Usuario $usuario, int $idEmpresaActiva, array $idsProductos): int
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $productos = $proveedor->productos()
            ->where('Activo', 1)
            ->where('Bloqueado', 0)
            ->whereIn('Id_Producto', $idsProductos)
            ->with('documentos.tipoDocumento')
            ->get();

        if ($productos->isEmpty()) {
            throw ValidationException::withMessages([
                'productos' => ['Selecciona al menos un producto disponible para registrar (los que ya están en revisión no cuentan).'],
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
            ->where('Bloqueado', 0)
            ->whereIn('Id_Producto', $idsProductos)
            ->update([
                'Bloqueado' => 1,
                'Estado_Calificacion' => 'Pendiente',
                'Comentario_Calificacion' => null,
                'Calificado_Por' => null,
                'Fecha_Calificacion' => null,
            ]);

        return $productos->count();
    }

    /**
     * "Registrar productos actualizados": el proveedor confirma que ya
     * corrigió todo lo que el admin había rechazado. Exige que no quede
     * NINGÚN producto activo en estado "Rechazado" (si queda alguno sin
     * corregir, se rechaza indicando cuántos faltan) -> recién ahí
     * apaga Correcciones_Pendientes_Productos, y esos productos vuelven
     * a quedar bloqueados (solo lectura) hasta que el admin dé su
     * retroalimentación de nuevo.
     */
    public function confirmarCorrecciones(Usuario $usuario, int $idEmpresaActiva): void
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if (! $proveedor->Correcciones_Pendientes_Productos) {
            throw ValidationException::withMessages([
                'productos' => ['No tienes correcciones pendientes de confirmar.'],
            ]);
        }

        $pendientes = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Rechazado')
            ->count();

        if ($pendientes > 0) {
            throw ValidationException::withMessages([
                'productos' => ["Todavía te falta corregir {$pendientes} producto(s) rechazado(s)."],
            ]);
        }

        $proveedor->forceFill(['Correcciones_Pendientes_Productos' => false])->save();
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