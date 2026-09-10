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
use App\Shared\VerificaArchivoFisico;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductoService
{
    use MueveArchivoAHistorico, VerificaArchivoFisico;

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

    public function listar(
        Usuario $usuario,
        int $idEmpresaActiva,
        ?string $busqueda = null,
        int $pagina = 1,
        int $porPagina = 20,
        ?string $estado = null,
        ?int $idProveedorObjetivo = null
    ) {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

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
            // OJO: antes este filtro llegaba desde el controller pero el
            // método ni siquiera lo recibía como parámetro -> PHP lo
            // descartaba en silencio (de más está decir que nunca filtró
            // nada). "Pendiente" y "En revisión" comparten
            // Estado_Calificacion="Pendiente" -> la diferencia real está
            // en Bloqueado (recién enviado y en revisión = bloqueado;
            // todavía no enviado = no bloqueado).
            //
            // Ahora además soporta varios estados a la vez (filtro tipo
            // BC, ej. "aprobado,rechazado") -> cada uno arma su propio
            // sub-where y se unen todos con OR, así un producto que
            // matchee CUALQUIERA de los seleccionados entra al resultado.
            ->when($estado, function ($query) use ($estado) {
                $estadosSolicitados = array_filter(explode(',', $estado));

                $query->where(function ($q) use ($estadosSolicitados) {
                    foreach ($estadosSolicitados as $estadoSolicitado) {
                        $q->orWhere(function ($sub) use ($estadoSolicitado) {
                            match ($estadoSolicitado) {
                                'aprobado' => $sub->where('Estado_Calificacion', 'Aprobado'),
                                'rechazado' => $sub->where('Estado_Calificacion', 'Rechazado'),
                                'en_revision' => $sub->where('Estado_Calificacion', 'Pendiente')->where('Bloqueado', 1),
                                // Un producto recién creado (nunca enviado a
                                // calificación) no tiene Estado_Calificacion
                                // seteado en absoluto -> queda NULL en la
                                // base, no el string "Pendiente" (eso recién
                                // se escribe en registrar()). Por eso acá hay
                                // que aceptar los dos: NULL o "Pendiente".
                                'pendiente' => $sub->where('Bloqueado', 0)->where(function ($q2) {
                                    $q2->where('Estado_Calificacion', 'Pendiente')->orWhereNull('Estado_Calificacion');
                                }),
                                default => $sub->whereRaw('1 = 0'), // valor desconocido -> no matchea nada
                            };
                        });
                    }
                });
            })
            ->with(['unidadPresentacion', 'grupos', 'documentos.tipoDocumento', 'documentos.archivo'])
            ->orderBy('Nombre_Producto')
            ->paginate($porPagina, ['*'], 'page', $pagina);
    }

    public function crear(Usuario $usuario, int $idEmpresaActiva, array $data, ?int $idProveedorObjetivo = null): Producto
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

        // Ya no se bloquea acá: un producto NUEVO no tiene nada que ver
        // con otros productos que estén en revisión -> nace con
        // Bloqueado=0, editable, sin importar cuántos lotes tenga el
        // proveedor pendientes de calificación en paralelo.

        // Transacción porque son dos escrituras (el producto y sus grupos) y
        // un producto a medio etiquetar no le sirve a nadie.
        return DB::transaction(function () use ($usuario, $proveedor, $data) {
            $producto = Producto::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Unidad_Presentacion' => $data['id_unidad_presentacion'],
                // Homogeneizar mayúsculas sin importar cómo lo escriba el proveedor.
                'Nombre_Producto' => mb_strtoupper($data['nombre_producto'], 'UTF-8'),
                'Codigo_Barras' => $data['codigo_barras'] ?? null,
                'Precio' => $data['precio'] ?? null,
                'Peso' => $data['peso'] ?? null,
                'Volumen' => $data['volumen'] ?? null,
                'Unidad_Por_Caja' => $data['unidad_por_caja'] ?? null,
                'Activo' => 1,
                'Bloqueado' => 0,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            $producto->grupos()->sync($data['grupos'] ?? []);

            // Se carga también la unidad: ProductoResource la expone como
            // texto ya resuelto, y sin el eager loading la respuesta del alta
            // salía SIN esa clave, distinta en forma de la que devuelven el
            // listado y la edición.
            return $producto->load(['unidadPresentacion', 'grupos']);
        });
    }

    /**
     * Edita los datos de un producto que TODAVÍA se puede tocar.
     *
     * Hasta ahora no existía forma de editar un producto: se creaba y, si
     * algo salía mal, había que borrarlo y volver a cargarlo con sus
     * documentos otra vez. Con el catálogo del comprador (miles de
     * productos por proveedor) eso dejó de ser aceptable.
     *
     * QUÉ SE PUEDE EDITAR Y CUÁNDO. Hay tres situaciones, y solo una
     * bloquea:
     *
     *  - TODAVÍA NO ENVIADO (Bloqueado = 0). Se edita libremente y no pasa
     *    nada más: nunca salió a calificación.
     *
     *  - RECHAZADO, con correcciones pendientes de confirmar. Se edita, y
     *    tampoco cambia de estado acá: el reenvío lo hace después
     *    confirmarCorreccionProducto, que además exige que se haya tocado
     *    algún documento.
     *
     *  - YA APROBADO. TAMBIÉN SE EDITA (decisión del usuario,
     *    10-sep-2026), pero editarlo lo DEVUELVE A CALIFICACIÓN: vuelve a
     *    Bloqueado = 1 / Pendiente, exactamente como si se acabara de
     *    registrar. Es la parte importante de la regla -sin ella, cualquiera
     *    podría cambiarle el nombre o el precio a un producto aprobado y el
     *    producto seguiría figurando como aprobado con datos que nadie
     *    revisó-.
     *
     *  - EN REVISIÓN (Bloqueado = 1 y Pendiente). Esta sí se rechaza: hay
     *    alguien de Calidad mirándolo AHORA, y cambiarle los datos por
     *    debajo haría que apruebe o rechace algo distinto de lo que tiene
     *    en pantalla.
     *
     * EL PRECIO VIAJA CON EL RESTO. Al editar un producto aprobado, el
     * precio nuevo entra en la misma calificación que todo lo demás. La
     * solicitud de cambio de precio (ver SolicitudCambioPrecioService)
     * sigue existiendo para el caso en que SOLO se quiere mover el precio
     * sin reenviar el producto entero -que es más barato para el proveedor,
     * porque el resto del producto no pierde su aprobación-.
     *
     * La única excepción es el producto que YA tiene una solicitud de
     * precio abierta: ahí el precio está congelado esperando esa
     * resolución, y pisarlo por un costado dejaría a la solicitud
     * comparando contra un precio que ya no existe.
     */
    public function actualizar(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idProducto,
        array $data,
        ?int $idProveedorObjetivo = null
    ): Producto {
        $producto = $this->productoDeTrabajo($usuario, $idEmpresaActiva, $idProducto, $idProveedorObjetivo);

        $estaAprobado = $producto->Estado_Calificacion === 'Aprobado';

        $esCorreccionDeRechazado = $producto->proveedor->Correcciones_Pendientes_Productos
            && $producto->Estado_Calificacion === 'Rechazado';

        if ($producto->Bloqueado && ! $estaAprobado && ! $esCorreccionDeRechazado) {
            throw new AccessDeniedHttpException(
                'Este producto está en revisión en este momento y no se puede editar hasta que lo califiquen.'
            );
        }

        $quiereCambiarPrecio = array_key_exists('precio', $data)
            && (float) ($data['precio'] ?? 0) !== (float) ($producto->Precio ?? 0);

        if ($producto->Precio_En_Revision && $quiereCambiarPrecio) {
            throw ValidationException::withMessages([
                'precio' => ['Este producto tiene un cambio de precio pendiente de aprobación; el precio no se puede editar hasta que se resuelva.'],
            ]);
        }

        return DB::transaction(function () use ($usuario, $producto, $data, $estaAprobado) {
            $cambios = [
                'Id_Unidad_Presentacion' => $data['id_unidad_presentacion'],
                'Nombre_Producto' => mb_strtoupper($data['nombre_producto'], 'UTF-8'),
                'Codigo_Barras' => $data['codigo_barras'] ?? null,
                'Precio' => $producto->Precio_En_Revision ? $producto->Precio : ($data['precio'] ?? null),
                'Peso' => $data['peso'] ?? null,
                'Volumen' => $data['volumen'] ?? null,
                'Unidad_Por_Caja' => $data['unidad_por_caja'] ?? null,
                'Modificado_Por' => $usuario->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ];

            if ($estaAprobado) {
                // Mismos campos que escribe registrar(): el producto entra a
                // la cola de calificación como cualquier otro, y se limpia la
                // calificación anterior para que nadie lea el "Aprobado" de
                // ayer como si fuera el de estos datos nuevos.
                $cambios += [
                    'Bloqueado' => 1,
                    'Estado_Calificacion' => 'Pendiente',
                    'Comentario_Calificacion' => null,
                    'Calificado_Por' => null,
                    'Fecha_Calificacion' => null,
                ];
            }

            $producto->forceFill($cambios)->save();

            // array_key_exists y no ?? : una petición que NO manda 'grupos'
            // deja los grupos como estaban, mientras que una que manda un
            // arreglo vacío los borra a propósito. Con ?? [] las dos cosas
            // se verían igual y no habría forma de quitar todas las
            // etiquetas de un producto.
            if (array_key_exists('grupos', $data)) {
                $producto->grupos()->sync($data['grupos'] ?? []);
            }

            if ($estaAprobado) {
                // Igual que en registrar(): se está ABRIENDO una ronda nueva
                // de calificación para este proveedor. Sin esto, el admin
                // seguiría viendo "ya calificaste todo" para siempre y este
                // producto no aparecería nunca en su bandeja.
                $proveedor = $producto->proveedor;

                if ($proveedor->Fecha_Registro_Calificacion_Productos !== null) {
                    $proveedor->forceFill(['Fecha_Registro_Calificacion_Productos' => null])->save();
                }
            }

            return $producto->load(['unidadPresentacion', 'grupos', 'documentos.tipoDocumento', 'documentos.archivo']);
        });
    }

    /**
     * Elimina un producto completo (borrado físico, ya que aún no ha sido
     * enviado a calificación -> no requiere trazabilidad histórica). Solo
     * permitido mientras el proveedor NO esté bloqueado (envío pendiente).
     */
    public function eliminar(Usuario $usuario, int $idEmpresaActiva, int $idProducto, ?int $idProveedorObjetivo = null): void
    {
        $producto = $this->productoDeTrabajo($usuario, $idEmpresaActiva, $idProducto, $idProveedorObjetivo);

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
    public function eliminarMasivo(Usuario $usuario, int $idEmpresaActiva, array $idsProductos, ?int $idProveedorObjetivo = null): int
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

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
    public function eliminarDocumento(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProducto, ?int $idProveedorObjetivo = null): void
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

        $documento = DocumentoProducto::whereHas('producto', fn($q) => $q->where('Id_Proveedor', $proveedor->Id_Proveedor))
            ->with('archivo', 'producto.proveedor', 'tipoDocumento')
            ->findOrFail($idDocumentoProducto);

        // Mismo criterio que en Documentos del proveedor: los tipos de
        // documento OBLIGATORIOS (Ficha técnica, Análisis de
        // Laboratorio) nunca se pueden borrar del todo, solo
        // reemplazar. Antes esto solo se ocultaba en el frontend.
        if ($documento->tipoDocumento->Obligatorio) {
            throw ValidationException::withMessages([
                'archivo' => ['Este documento es obligatorio, no se puede eliminar. Puede reemplazarlo por otro.'],
            ]);
        }

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

    /**
     * Catálogo activo de tipos de documento de producto -> lo consume
     * el front para armar el checklist dinámicamente (antes venía
     * hardcodeado en ModalDocumentosProducto.tsx con solo 3 tipos fijos).
     */
    public function listarTiposDocumento(): \Illuminate\Support\Collection
    {
        return TipoDocumentoProducto::where('Activo', 1)->get();
    }

    public function subirDocumento(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idProducto,
        int $idTipoDocumentoProducto,
        UploadedFile $archivo,
        ?string $fechaCaducidad = null,
        ?string $nombreDocumento = null,
        ?int $idProveedorObjetivo = null
    ): DocumentoProducto {
        $producto = $this->productoDeTrabajo($usuario, $idEmpresaActiva, $idProducto, $idProveedorObjetivo);

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

        if ($tipo->Requiere_Fecha_Caducidad && ! $fechaCaducidad) {
            throw ValidationException::withMessages([
                'fecha_caducidad' => ['Este documento requiere fecha de caducidad.'],
            ]);
        }

        // Mismo criterio que DocumentoProveedorService::subirDocumento:
        // tipos "Permite_Multiples" (ej. "Hojas de seguridad") necesitan
        // que el proveedor indique cómo se llama cada archivo, porque
        // puede haber varios y no se distinguirían entre sí.
        if ($tipo->Permite_Multiples && ! $nombreDocumento) {
            throw ValidationException::withMessages([
                'nombre_documento' => ['Indica el nombre de este documento.'],
            ]);
        }

        return DB::transaction(function () use ($usuario, $producto, $tipo, $archivo, $fechaCaducidad, $nombreDocumento) {
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
            // Igual que en Documentos del proveedor: para tipos
            // "Permite_Multiples" (ej. "Hojas de seguridad") el nombre
            // que escribió el proveedor entra en el nombre físico, para
            // poder distinguir varios archivos del mismo tipo entre sí.
            $nombrePersonalizado = $tipo->Permite_Multiples ? SaneadorNombreArchivo::sanear($nombreDocumento) : null;

            $carpeta = "proveedores/{$ruc}/{$producto->proveedor->Id_Empresa}_{$nombreEmpresa}/doc_productos/"
                ."{$producto->Id_Producto}_{$nombreProducto}/{$tipo->Carpeta_Slug}";
            $partesNombre = array_filter([$codigo, $nombrePersonalizado, (string) $producto->Id_Producto, $razonSocial, (string) $registroArchivo->Id_Archivo]);
            $nombreFisico = implode('_', $partesNombre).".{$extension}";

            Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombreFisico);

            $registroArchivo->update([
                'Nombre_Original' => $nombreFisico,
                'Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}",
            ]);

            // El(los) documento(s) anteriores de este mismo tipo se
            // desactivan -> EXCEPTO si el tipo permite múltiples (ej.
            // "Hojas de seguridad"), donde este archivo se suma a los
            // que ya había en vez de reemplazarlos. El archivo físico
            // viejo se archiva en "historico/" SOLO si de verdad fue el
            // que un admin vio y rechazó (comparando su fecha de
            // creación contra la fecha de calificación del producto) ->
            // si es un archivo subido durante esta misma corrección,
            // que nadie revisó todavía, se borra directo. Ver
            // MueveArchivoAHistorico para el detalle de por qué hace
            // falta esta comparación acá.
            if (! $tipo->Permite_Multiples) {
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
            }

            $nuevoDocumento = DocumentoProducto::create([
                'Id_Producto' => $producto->Id_Producto,
                'Id_Tipo_Documento_Producto' => $tipo->Id_Tipo_Documento_Producto,
                'Id_Archivo' => $registroArchivo->Id_Archivo,
                'Fecha_Caducidad' => $fechaCaducidad,
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
    public function resumenRegistro(Usuario $usuario, int $idEmpresaActiva, ?array $idsProductos = null, ?int $idProveedorObjetivo = null): array
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

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
    public function registrar(Usuario $usuario, int $idEmpresaActiva, array $idsProductos, ?int $idProveedorObjetivo = null): int
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

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

        DB::transaction(function () use ($proveedor, $idsProductos) {
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

            // Sin esto, un lote nuevo se enviaba a revisión pero el admin
            // seguía viendo "ya calificaste todo, no hay nada más que
            // hacer" para siempre -> Fecha_Registro_Calificacion_Productos
            // es la marca de "ya cerré esta ronda", y acá se está abriendo
            // una ronda nueva (este lote), así que hay que reabrirla.
            if ($proveedor->Fecha_Registro_Calificacion_Productos !== null) {
                $proveedor->forceFill(['Fecha_Registro_Calificacion_Productos' => null])->save();
            }
        });

        return $productos->count();
    }

    /**
     * "Registrar corrección" de UN producto puntual. Antes esto era una
     * sola acción para TODO el catálogo rechazado a la vez
     * (confirmarCorrecciones) -> el botón vivía en la tarjeta de cada
     * producto (dando a entender que era una acción individual), pero
     * por debajo revisaba el catálogo entero: corregir un producto
     * podía frenarse -o de peor, arrastrar al envío- productos que el
     * proveedor ni había tocado. Ahora cada producto se corrige y se
     * reenvía de manera completamente independiente, igual que ya
     * funciona el registro de un producto nuevo (ver registrar()).
     *
     * Exige que ESTE producto tenga sus documentos obligatorios
     * completos Y que al menos uno se haya subido DESPUÉS de
     * Fecha_Calificacion (mismo dato que ya usa subirDocumento para
     * decidir si el archivo viejo va a histórico) -> así un producto
     * que nadie tocó desde el rechazo no se puede dar por corregido,
     * aunque sus casillas de documentos estén "llenas" con los mismos
     * archivos que el admin ya vio y rechazó.
     */
    public function confirmarCorreccionProducto(Usuario $usuario, int $idEmpresaActiva, int $idProducto, ?int $idProveedorObjetivo = null): void
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

        $producto = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Id_Producto', $idProducto)
            ->where('Estado_Calificacion', 'Rechazado')
            ->with('documentos')
            ->firstOrFail();

        $tiposObligatorios = TipoDocumentoProducto::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->pluck('Id_Tipo_Documento_Producto');

        $documentosActivos = $producto->documentos->where('Activo', true);
        $tiposSubidos = $documentosActivos->pluck('Id_Tipo_Documento_Producto');

        if ($tiposObligatorios->diff($tiposSubidos)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'producto' => ['Todavía te falta cargar algún documento obligatorio de este producto.'],
            ]);
        }

        $seTocoAlgoDesdeElRechazo = $producto->Fecha_Calificacion === null
            || $documentosActivos->contains(
                fn (DocumentoProducto $doc) => $doc->Fecha_Creacion !== null
                    && $doc->Fecha_Creacion->gt($producto->Fecha_Calificacion)
            );

        if (! $seTocoAlgoDesdeElRechazo) {
            throw ValidationException::withMessages([
                'producto' => ['Todavía no reemplazaste ningún documento desde que se rechazó este producto.'],
            ]);
        }

        DB::transaction(function () use ($proveedor, $producto) {
            $producto->forceFill([
                'Bloqueado' => 1,
                'Estado_Calificacion' => 'Pendiente',
                'Comentario_Calificacion' => null,
                'Calificado_Por' => null,
                'Fecha_Calificacion' => null,
            ])->save();

            // Reabre la ronda de calificación para el admin (mismo
            // motivo que en registrar()) -> este producto puntual
            // recién vuelve a pedir revisión, sin importar en qué
            // estado esté el resto del catálogo.
            if ($proveedor->Fecha_Registro_Calificacion_Productos !== null) {
                $proveedor->forceFill(['Fecha_Registro_Calificacion_Productos' => null])->save();
            }

            // El flag a nivel proveedor (usado para el banner "tienes
            // correcciones pendientes") recién se apaga cuando YA NO
            // queda ningún producto rechazado -> antes se apagaba
            // entero apenas se corregía un producto, aunque quedaran
            // otros rechazados sin tocar todavía.
            $quedanRechazados = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->where('Estado_Calificacion', 'Rechazado')
                ->exists();

            if (! $quedanRechazados && $proveedor->Correcciones_Pendientes_Productos) {
                $proveedor->forceFill(['Correcciones_Pendientes_Productos' => false])->save();
            }
        });
    }

    /**
     * DE QUIÉN son los productos sobre los que se está trabajando.
     *
     * Es el ÚNICO punto de todo el servicio que decide eso, y por eso es el
     * único que cambió cuando se le abrió la ficha de productos al rol
     * Compras (10-sep-2026). Todo lo que viene después -qué se puede
     * editar, cuándo un producto queda bloqueado, qué documentos son
     * obligatorios para mandarlo a aprobar- corre exactamente igual sin
     * enterarse de quién lo pidió.
     *
     * POR QUÉ ASÍ Y NO UN SERVICIO APARTE PARA EL COMPRADOR. La alternativa
     * era duplicar este archivo entero recibiendo Id_Proveedor (como sí
     * están separados FichaProveedorService y CalificacionProveedorService).
     * Ahí tenía sentido porque son DOS OPERACIONES distintas: una llena la
     * ficha, la otra la califica. Acá es la MISMA operación hecha por otra
     * persona, así que dos copias significarían mantener dos veces las
     * reglas de bloqueo, de correcciones y de documentos obligatorios, y
     * que se separen en cuanto alguien corrija una sola de las dos.
     *
     * Dos caminos:
     *
     *  - SIN $idProveedorObjetivo (el de siempre): el usuario externo sobre
     *    SU propia ficha. El Id_Proveedor NUNCA llega desde el cliente, se
     *    resuelve del usuario autenticado + la empresa activa.
     *
     *  - CON $idProveedorObjetivo: personal interno cargando productos EN
     *    NOMBRE de un proveedor. Acá el id SÍ viene del cliente, así que se
     *    comprueban las dos cosas: que el rol pueda, y que ese proveedor
     *    sea de la empresa activa. Sin lo segundo, cambiar un número en la
     *    URL alcanzaría para editar el catálogo de otra empresa.
     */
    protected function proveedorDeTrabajo(Usuario $usuario, int $idEmpresaActiva, ?int $idProveedorObjetivo = null): Proveedor
    {
        if ($idProveedorObjetivo === null) {
            if ($usuario->Tipo_Usuario !== 'Proveedor') {
                throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) gestionan su ficha de productos.');
            }

            $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

            if (! $proveedor) {
                throw new NotFoundHttpException('Este usuario no tiene un Proveedor asociado a la empresa activa.');
            }

            return $proveedor;
        }

        $this->verificarAccesoInterno($usuario, $idEmpresaActiva);

        $proveedor = Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->find($idProveedorObjetivo);

        if (! $proveedor) {
            throw new NotFoundHttpException('El proveedor indicado no existe o no pertenece a la empresa activa.');
        }

        return $proveedor;
    }

    /**
     * Quién puede cargar productos en nombre de un proveedor: Compras (el
     * "comprador"), Admin y Sistemas. Se valida por EMPRESA ACTIVA y no de
     * forma global, porque los proveedores que se ven son los de esa
     * empresa.
     *
     * Público porque la pantalla del comprador también necesita
     * preguntarlo antes de listar proveedores (ver
     * ProductosDeProveedorController::proveedores).
     */
    public function verificarAccesoInterno(Usuario $usuario, int $idEmpresaActiva): void
    {
        $puedeAcceder = $usuario->esCompras($idEmpresaActiva)
            || $usuario->esAdmin($idEmpresaActiva)
            || $usuario->esSistemas($idEmpresaActiva);

        if (! $puedeAcceder) {
            throw new AccessDeniedHttpException(
                'Solo los roles Compras, Admin y Sistemas pueden gestionar los productos de un proveedor.'
            );
        }
    }

    protected function productoDeTrabajo(Usuario $usuario, int $idEmpresaActiva, int $idProducto, ?int $idProveedorObjetivo = null): Producto
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

        return Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->with('proveedor')
            ->findOrFail($idProducto);
    }

    public function descargarDocumento(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProducto, ?int $idProveedorObjetivo = null)
    {
        $proveedor = $this->proveedorDeTrabajo($usuario, $idEmpresaActiva, $idProveedorObjetivo);

        $documento = DocumentoProducto::whereHas('producto', fn($q) => $q->where('Id_Proveedor', $proveedor->Id_Proveedor))
            ->with('archivo')
            ->findOrFail($idDocumentoProducto);

        $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

        $this->verificarArchivoEntregable($rutaCompleta);

        return response()->file($rutaCompleta, [
            'Content-Type' => $documento->archivo->Tipo_Mime,
            'Content-Disposition' => 'inline; filename="' . $documento->archivo->Nombre_Original . '"',
        ]);
    }
}