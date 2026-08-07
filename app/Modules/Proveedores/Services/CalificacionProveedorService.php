<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Proveedores\Models\CalificacionCampoFicha;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Notifications\ProveedorAprobadoNotification;
use App\Modules\Proveedores\Notifications\ProveedorRechazadoNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Calificación de proveedores por un usuario interno (Admin/Sistemas):
 * la Ficha se califica CAMPO POR CAMPO (cada dato tiene su propio
 * Aprobado/Rechazado + observación si se rechaza -> ver
 * calificarCampoFicha), y cada documento cargado se califica de forma
 * individual también (mismo esquema, ver calificarDocumento).
 *
 * Deliberadamente separado de FichaProveedorService/DocumentoProveedorService:
 * esos dos son exclusivos del propio usuario Proveedor sobre SU PROPIA
 * ficha/documentación (resuelven todo desde el usuario autenticado). Este
 * servicio es lo opuesto: un usuario interno viendo/calificando la ficha
 * de CUALQUIER proveedor de su empresa, recibiendo el Id_Proveedor
 * explícito. Mezclar ambos hubiera significado parchear cada método con
 * "si es admin, salta esta validación" por todos lados.
 */
class CalificacionProveedorService
{
    protected const DISCO = 'repositorio_proveedores';

    /**
     * Campos calificables de la Ficha (Sección 1) uno por uno + las 2
     * secciones que son de selección múltiple, esas se califican como
     * bloque completo (no tendría sentido calificar cada clase/categoría
     * marcada por separado). Esta lista es la fuente de verdad de qué
     * "Nombre_Campo" son válidos -> el front usa la misma lista (ver
     * CAMPOS_CALIFICABLES en el módulo de proveedores del front).
     */
    public const CAMPOS_SECCION1 = [
        'ruc', 'clase_contribuyente', 'razon_social', 'nombre_comercial',
        'email', 'telefono', 'direccion', 'ciudad', 'pagina_web',
        'representante_legal', 'correo_representante', 'telefono_representante',
        'contacto_venta', 'correo_venta', 'telefono_contacto_venta',
        'contacto_calidad', 'correo_calidad', 'telefono_contacto_calidad',
        'contacto_contabilidad', 'correo_contabilidad', 'telefono_contabilidad',
    ];

    public const CAMPO_CLASE = 'clase_proveedor';
    public const CAMPO_CATEGORIA = 'categoria_productos';

    // IDs de Estado_Proveedor -> mismo criterio que el resto del código
    // (ver los TODO en ProveedorController/UsuarioService): por ahora
    // hardcodeados con un comentario, hasta que se centralicen en una
    // constante/enum compartida.
    protected const ESTADO_ASPIRANTE = 1;
    protected const ESTADO_APROBADO = 2;
    protected const ESTADO_RECHAZADO = 3;

    /**
     * Mismo mapeo que ETIQUETAS_CAMPOS_FICHA en
     * shared/constants/camposFichaProveedor.ts del front -> solo se usa
     * acá para armar un correo legible ("Razón social" en vez de
     * "razon_social"), no cambia ninguna validación.
     */
    protected const ETIQUETAS_CAMPOS_FICHA = [
        'ruc' => 'RUC',
        'clase_contribuyente' => 'Clase de contribuyente',
        'razon_social' => 'Razón social',
        'nombre_comercial' => 'Nombre comercial',
        'email' => 'Correo',
        'telefono' => 'Teléfono',
        'direccion' => 'Dirección',
        'ciudad' => 'Ciudad',
        'pagina_web' => 'Página web',
        'representante_legal' => 'Representante legal · Nombre',
        'correo_representante' => 'Representante legal · Correo',
        'telefono_representante' => 'Representante legal · Teléfono',
        'contacto_venta' => 'Contacto de ventas · Nombre',
        'correo_venta' => 'Contacto de ventas · Correo',
        'telefono_contacto_venta' => 'Contacto de ventas · Teléfono',
        'contacto_calidad' => 'Contacto de calidad · Nombre',
        'correo_calidad' => 'Contacto de calidad · Correo',
        'telefono_contacto_calidad' => 'Contacto de calidad · Teléfono',
        'contacto_contabilidad' => 'Contacto de contabilidad · Nombre',
        'correo_contabilidad' => 'Contacto de contabilidad · Correo',
        'telefono_contabilidad' => 'Contacto de contabilidad · Teléfono',
        'clase_proveedor' => 'Clase de Proveedor',
        'categoria_productos' => 'Categoría de Productos',
    ];

    public function obtenerFicha(Usuario $admin, int $idEmpresaActiva, int $idProveedor): Proveedor
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        return $this->proveedorDeLaEmpresa($idEmpresaActiva, $idProveedor)
            ->load(['clases', 'categoriasProducto', 'estado', 'calificacionesCampos']);
    }

    /**
     * Calificación GENERAL de la ficha, en un solo envío: Aprobar (todos
     * los campos quedan Aprobados) o Rechazar (el admin marcó ciertos
     * campos como inválidos, cada uno con su observación -> esos quedan
     * Rechazados, TODO el resto de campos que no se marcó queda
     * Aprobado, ya que el admin los revisó y no los señaló como
     * problema).
     *
     * Después de esto, la ficha queda "calificada" (Aprobada o
     * Rechazada) y el admin ya no puede volver a tocar nada acá hasta
     * que el proveedor corrija los campos rechazados -> eso reabre
     * automáticamente esos campos (ver FichaProveedorService::
     * reabrirRevisionSiEstabaRechazada), y ahí sí vuelve a estar
     * disponible para calificar de nuevo.
     */
    public function calificarFichaGeneral(
        Usuario $admin,
        int $idEmpresaActiva,
        int $idProveedor,
        bool $aprobado,
        array $camposRechazados = []
    ): Proveedor {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $proveedor = $this->proveedorDeLaEmpresa($idEmpresaActiva, $idProveedor);

        if ($this->yaEstaCalificada($proveedor)) {
            throw ValidationException::withMessages([
                'ficha' => ['Esta ficha ya está calificada. Solo se puede volver a calificar cuando el proveedor corrija lo señalado.'],
            ]);
        }

        $todosLosCampos = [...self::CAMPOS_SECCION1, self::CAMPO_CLASE, self::CAMPO_CATEGORIA];
        $mapaRechazados = collect($camposRechazados)->keyBy('campo');

        foreach ($todosLosCampos as $campo) {
            $rechazo = $mapaRechazados->get($campo);

            CalificacionCampoFicha::updateOrCreate(
                ['Id_Proveedor' => $proveedor->Id_Proveedor, 'Nombre_Campo' => $campo],
                [
                    'Estado' => $rechazo ? 'Rechazado' : 'Aprobado',
                    'Comentario' => $rechazo['observacion'] ?? null,
                    'Calificado_Por' => $admin->Id_Usuario,
                    'Fecha_Calificacion' => now(),
                ]
            );
        }

        $this->activarSiCorrespondeAprobado($proveedor);

        return $proveedor->fresh(['clases', 'categoriasProducto', 'estado', 'calificacionesCampos']);
    }

    /**
     * true si la ficha ya tiene una calificación general puesta
     * (Aprobada o Rechazada) -> mientras sea así, calificarFichaGeneral()
     * se bloquea: el admin ya tomó una decisión, no hay nada más que
     * hacer hasta que el proveedor corrija.
     */
    protected function yaEstaCalificada(Proveedor $proveedor): bool
    {
        return $proveedor->fresh('calificacionesCampos')->estadoGeneralCalificacionFicha() !== null;
    }

    /**
     * Mismo shape que DocumentoProveedorService::obtenerChecklist(), pero
     * viendo el checklist de CUALQUIER proveedor (no "el mío") y con los
     * campos de calificación de cada documento incluidos.
     */
    public function obtenerChecklistDocumentos(Usuario $admin, int $idEmpresaActiva, int $idProveedor): array
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $proveedor = $this->proveedorDeLaEmpresa($idEmpresaActiva, $idProveedor);
        $esQuito = strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0;

        $tipos = TipoDocumento::where('Activo', 1)
            ->where(function ($query) use ($esQuito) {
                $query->where('Requiere_Solo_Quito', 0);
                if ($esQuito) {
                    $query->orWhere('Requiere_Solo_Quito', 1);
                }
            })
            ->with(['documentosProveedor' => function ($query) use ($proveedor) {
                $query->where('Id_Proveedor', $proveedor->Id_Proveedor)
                    ->where('Activo', 1)
                    ->with('archivo');
            }])
            ->orderBy('Categoria')
            ->get();

        return [
            'razon_social' => $proveedor->Razon_Social,
            'documentacion_registrada' => $proveedor->Fecha_Registro_Documentacion !== null,
            // true = ya se calificaron todos y el admin confirmó con
            // "Registrar calificación" -> queda de solo lectura hasta que
            // el proveedor corrija algún documento rechazado (eso resetea
            // este campo, ver DocumentoProveedorService::reemplazarDocumento).
            'calificacion_documentos_registrada' => $proveedor->Fecha_Registro_Calificacion_Documentos !== null,
            'documentos' => $tipos->map(fn (TipoDocumento $tipo) => [
                'id_tipo_documento' => $tipo->Id_Tipo_Documento,
                'categoria' => $tipo->Categoria,
                'nombre_documento' => $tipo->Nombre_Documento,
                'obligatorio' => (bool) $tipo->Obligatorio,
                'documentos' => $tipo->documentosProveedor->map(fn (DocumentoProveedor $doc) => [
                    'id_documento_proveedor' => $doc->Id_Documento_Proveedor,
                    'nombre_original' => $doc->archivo->Nombre_Original,
                    'fecha_caducidad' => $doc->Fecha_Caducidad?->toDateString(),
                    'fecha_subida' => $doc->Fecha_Creacion?->toIso8601String(),
                    'estado_calificacion' => $doc->Estado_Calificacion,
                    'comentario_calificacion' => $doc->Comentario_Calificacion,
                    'fecha_calificacion' => $doc->Fecha_Calificacion?->toIso8601String(),
                ])->values(),
            ])->values(),
        ];
    }

    public function calificarDocumento(
        Usuario $admin,
        int $idEmpresaActiva,
        int $idDocumentoProveedor,
        bool $aprobado,
        ?string $observacion
    ): DocumentoProveedor {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $documento = DocumentoProveedor::whereHas(
            'proveedor',
            fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)
        )
            ->where('Activo', 1)
            ->with('archivo', 'tipoDocumento', 'proveedor')
            ->findOrFail($idDocumentoProveedor);

        if ($documento->proveedor->Fecha_Registro_Calificacion_Documentos !== null) {
            throw ValidationException::withMessages([
                'documento' => ['Ya registraste la calificación de documentos. Solo se puede volver a calificar cuando el proveedor corrija algo rechazado.'],
            ]);
        }

        $documento->forceFill([
            'Estado_Calificacion' => $aprobado ? 'Aprobado' : 'Rechazado',
            'Comentario_Calificacion' => $observacion,
            'Calificado_Por' => $admin->Id_Usuario,
            'Fecha_Calificacion' => now(),
        ])->save();

        // Mientras el proveedor no confirme que ya corrigió TODO lo
        // rechazado (con "Registrar documentación actualizada"), este
        // documento y cualquier otro no-Aprobado quedan editables para
        // él -> ver DocumentoProveedorService::puedeEditarDocumento().
        if (! $aprobado) {
            $documento->proveedor->forceFill(['Correcciones_Pendientes' => true])->save();
        } else {
            $this->activarSiCorrespondeAprobado($documento->proveedor);
        }

        return $documento->fresh(['archivo', 'tipoDocumento']);
    }

    /**
     * Confirma la calificación de documentos: exige que TODOS los
     * documentos activos del proveedor ya tengan Estado_Calificacion
     * puesto (no puede quedar ninguno "Pendiente"). Después de esto, la
     * sección pasa a ser de solo consulta -> ver
     * calificacion_documentos_registrada en obtenerChecklistDocumentos.
     */
    public function registrarCalificacionDocumentos(Usuario $admin, int $idEmpresaActiva, int $idProveedor): void
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $proveedor = $this->proveedorDeLaEmpresa($idEmpresaActiva, $idProveedor);

        if ($proveedor->Fecha_Registro_Calificacion_Documentos !== null) {
            throw ValidationException::withMessages([
                'documentos' => ['Ya habías registrado esta calificación.'],
            ]);
        }

        $totalDocumentos = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->count();

        if ($totalDocumentos === 0) {
            throw ValidationException::withMessages([
                'documentos' => ['El proveedor todavía no cargó ningún documento.'],
            ]);
        }

        $pendientes = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->whereNull('Estado_Calificacion')
            ->count();

        if ($pendientes > 0) {
            throw ValidationException::withMessages([
                'documentos' => ["Todavía te falta calificar {$pendientes} documento(s)."],
            ]);
        }

        $proveedor->forceFill(['Fecha_Registro_Calificacion_Documentos' => now()])->save();
    }

    /**
     * Igual que DocumentoProveedorService::descargar(), pero con
     * Content-Disposition "inline" en vez de "attachment" -> el navegador
     * lo muestra directo en el visor de PDF integrado del admin en vez de
     * forzar la descarga, y sin la restricción de "solo mis documentos".
     */
    public function verDocumentoInline(Usuario $admin, int $idEmpresaActiva, int $idDocumentoProveedor)
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $documento = DocumentoProveedor::whereHas(
            'proveedor',
            fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)
        )
            ->with('archivo')
            ->findOrFail($idDocumentoProveedor);

        $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

        if (! is_file($rutaCompleta)) {
            throw new NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
        }

        return response()->file($rutaCompleta, [
            'Content-Type' => $documento->archivo->Tipo_Mime,
            'Content-Disposition' => 'inline; filename="'.$documento->archivo->Nombre_Original.'"',
        ]);
    }

    /**
     * Productos del proveedor con sus documentos, para que el admin los
     * revise y califique uno por uno. Solo se listan los que el
     * proveedor ya "Registró" (Bloqueado=1) -> mientras no los registre,
     * no hay nada que calificar todavía (ver ProductoService::registrar).
     */
    public function obtenerProductosCalificacion(Usuario $admin, int $idEmpresaActiva, int $idProveedor): array
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $proveedor = $this->proveedorDeLaEmpresa($idEmpresaActiva, $idProveedor);

        $productos = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Bloqueado', 1)
            ->with(['unidadPresentacion', 'documentos' => function ($query) {
                $query->where('Activo', 1)->with('archivo', 'tipoDocumento');
            }])
            ->get();

        return [
            'razon_social' => $proveedor->Razon_Social,
            // true = ya se calificaron todos los que estaban en revisión
            // y el admin confirmó con "Registrar calificación" -> queda
            // de solo lectura hasta que se reabra.
            'calificacion_productos_registrada' => $proveedor->Fecha_Registro_Calificacion_Productos !== null,
            'productos' => $productos->map(fn (Producto $producto) => [
                'id_producto' => $producto->Id_Producto,
                'nombre_producto' => $producto->Nombre_Producto,
                'codigo_barras' => $producto->Codigo_Barras,
                'unidad_presentacion' => $producto->unidadPresentacion?->Nombre_Unidad,
                'precio' => $producto->Precio,
                'estado_calificacion' => $producto->Estado_Calificacion,
                'comentario_calificacion' => $producto->Comentario_Calificacion,
                'fecha_calificacion' => $producto->Fecha_Calificacion?->toIso8601String(),
                'documentos' => $producto->documentos->map(fn ($doc) => [
                    'id_documento_producto' => $doc->Id_Documento_Producto,
                    'nombre_documento' => $doc->tipoDocumento->Nombre_Documento,
                    'nombre_original' => $doc->archivo->Nombre_Original,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Califica UN producto puntual. Mismo esquema Aprobado/Rechazado +
     * observación obligatoria al rechazar que ya usamos en documentos.
     */
    public function calificarProducto(
        Usuario $admin,
        int $idEmpresaActiva,
        int $idProducto,
        bool $aprobado,
        ?string $observacion
    ): Producto {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $producto = Producto::whereHas(
            'proveedor',
            fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)
        )
            ->where('Activo', 1)
            ->with('proveedor')
            ->findOrFail($idProducto);

        if ($producto->proveedor->Fecha_Registro_Calificacion_Productos !== null) {
            throw ValidationException::withMessages([
                'producto' => ['Ya registraste la calificación de productos. Solo se puede volver a calificar cuando se reabra.'],
            ]);
        }

        $producto->forceFill([
            'Estado_Calificacion' => $aprobado ? 'Aprobado' : 'Rechazado',
            'Comentario_Calificacion' => $observacion,
            'Calificado_Por' => $admin->Id_Usuario,
            'Fecha_Calificacion' => now(),
        ])->save();

        // Mientras el proveedor no confirme que ya corrigió TODO lo
        // rechazado (con "Registrar productos actualizados"), el
        // documento de ese producto puntual queda editable para él ->
        // ver ProductoService::puedeEditarProducto().
        if (! $aprobado) {
            $producto->proveedor->forceFill(['Correcciones_Pendientes_Productos' => true])->save();
        } else {
            $this->activarSiCorrespondeAprobado($producto->proveedor);
        }

        return $producto->fresh();
    }

    /**
     * Confirma la calificación de productos: exige que TODOS los
     * productos actualmente en revisión (Bloqueado=1, sin importar de
     * qué lote sean -> pueden coexistir varios en paralelo) ya tengan
     * Estado_Calificacion puesto (no puede quedar ninguno "Pendiente").
     * Después de esto, la sección pasa a ser de solo consulta.
     */
    public function registrarCalificacionProductos(Usuario $admin, int $idEmpresaActiva, int $idProveedor): void
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $proveedor = $this->proveedorDeLaEmpresa($idEmpresaActiva, $idProveedor);

        if ($proveedor->Fecha_Registro_Calificacion_Productos !== null) {
            throw ValidationException::withMessages([
                'productos' => ['Ya habías registrado esta calificación.'],
            ]);
        }

        $totalProductos = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Bloqueado', 1)
            ->count();

        if ($totalProductos === 0) {
            throw ValidationException::withMessages([
                'productos' => ['No hay productos en revisión para registrar.'],
            ]);
        }

        $pendientes = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Bloqueado', 1)
            ->where('Estado_Calificacion', 'Pendiente')
            ->count();

        if ($pendientes > 0) {
            throw ValidationException::withMessages([
                'productos' => ["Todavía te falta calificar {$pendientes} producto(s)."],
            ]);
        }

        $proveedor->forceFill(['Fecha_Registro_Calificacion_Productos' => now()])->save();

        // Este es el ÚLTIMO paso de la revisión (ficha y documentación ya
        // se calificaron antes) -> es el único punto donde tiene sentido
        // dar un veredicto final (Aprobado o Rechazado) y notificar por
        // correo, porque recién acá se sabe con certeza si quedó algún
        // producto aprobado o no.
        $this->resolverVeredictoFinal($proveedor, $admin);
    }

    /**
     * Igual que verDocumentoInline, pero para un documento de PRODUCTO
     * en vez de un documento de la ficha general.
     */
    public function verDocumentoProductoInline(Usuario $admin, int $idEmpresaActiva, int $idDocumentoProducto)
    {
        $this->verificarEsAdmin($admin, $idEmpresaActiva);

        $documento = \App\Modules\Ficha_Productos\Models\DocumentoProducto::whereHas(
            'producto.proveedor',
            fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)
        )
            ->with('archivo')
            ->findOrFail($idDocumentoProducto);

        $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

        if (! is_file($rutaCompleta)) {
            throw new NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
        }

        return response()->file($rutaCompleta, [
            'Content-Type' => $documento->archivo->Tipo_Mime,
            'Content-Disposition' => 'inline; filename="'.$documento->archivo->Nombre_Original.'"',
        ]);
    }

    /**
     * "Aspirante" -> "Aprobado" automático, apenas se cumplen las 3
     * condiciones: Ficha aprobada, Documentación aprobada (todo lo
     * cargado, calificado y sin ningún rechazo activo) y AL MENOS UN
     * producto aprobado (no hace falta que sean todos). Se llama desde
     * cada punto que podría ser "el último que faltaba" -> calificar la
     * ficha, calificar un documento, calificar un producto.
     *
     * Nunca DEGRADA el estado acá (si algo se rechaza después, eso no es
     * responsabilidad de este método) -> solo promueve hacia adelante, y
     * solo si todavía está en Aspirante (no pisa un estado manual que
     * un admin haya puesto a mano, ej. Suspendido).
     */
    /**
     * Barrido de reconciliación: activarSiCorrespondeAprobado() solo se
     * dispara como efecto secundario de calificar ficha/documento/
     * producto -> si la ÚLTIMA calificación que completó las 3
     * condiciones a la vez no fue, por lo que sea, la que terminó
     * cumpliéndolas todas juntas (ej. algún orden particular, o un
     * cambio manual en la base), el proveedor se queda atascado en
     * Aspirante para siempre, aunque en verdad ya cumpla todo. Este
     * método revisa a todos los Aspirantes y los vuelve a evaluar,
     * sin importar qué haya pasado antes. Pensado para correrse desde
     * un comando artisan (ver ReconciliarEstadosProveedoresCommand),
     * a mano cuando se detecte un caso así.
     *
     * @param callable(Proveedor, array): void|null $onDiagnostico Se
     *        llama con el proveedor y su diagnóstico ANTES de intentar
     *        activarlo, para poder loguear/imprimir qué condición es
     *        la que está fallando en cada caso (ver el comando).
     */
    public function reconciliarEstadosAspirantes(?callable $onDiagnostico = null): int
    {
        $activados = 0;

        Proveedor::where('Id_Estado_Proveedor', self::ESTADO_ASPIRANTE)
            ->where('Activo', 1)
            ->get()
            ->each(function (Proveedor $proveedor) use (&$activados, $onDiagnostico) {
                $diagnostico = $this->diagnosticarCondicionesAprobado($proveedor);

                if ($onDiagnostico) {
                    $onDiagnostico($proveedor, $diagnostico);
                }

                $this->activarSiCorrespondeAprobado($proveedor);

                if ($proveedor->fresh()->Id_Estado_Proveedor === self::ESTADO_APROBADO) {
                    $activados++;
                }
            });

        return $activados;
    }

    /**
     * Calcula las 3 condiciones para pasar a Aprobado, SIN escribir
     * nada -> separado de activarSiCorrespondeAprobado() para poder
     * mostrar el detalle de cada una (qué pasó y por qué) desde el
     * comando de diagnóstico, en vez de solo un sí/no.
     *
     * @return array{ficha_aprobada: bool, documentacion_aprobada: bool, total_documentos: int, documentos_no_aprobados: int, hay_producto_aprobado: bool}
     */
    public function diagnosticarCondicionesAprobado(Proveedor $proveedor): array
    {
        $fichaAprobada = $proveedor->fresh('calificacionesCampos')->estadoGeneralCalificacionFicha() === 'Aprobado';

        $totalDocumentos = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->count();
        $documentosNoAprobados = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where(function ($query) {
                $query->whereNull('Estado_Calificacion')->orWhere('Estado_Calificacion', 'Rechazado');
            })
            ->count();
        $documentacionAprobada = $totalDocumentos > 0 && $documentosNoAprobados === 0;

        $hayProductoAprobado = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Aprobado')
            ->exists();

        return [
            'ficha_aprobada' => $fichaAprobada,
            'documentacion_aprobada' => $documentacionAprobada,
            'total_documentos' => $totalDocumentos,
            'documentos_no_aprobados' => $documentosNoAprobados,
            'hay_producto_aprobado' => $hayProductoAprobado,
        ];
    }

    protected function activarSiCorrespondeAprobado(Proveedor $proveedor): void
    {
        $proveedor->refresh();

        if ($proveedor->Id_Estado_Proveedor !== self::ESTADO_ASPIRANTE) {
            return;
        }

        $diagnostico = $this->diagnosticarCondicionesAprobado($proveedor);

        if (! $diagnostico['ficha_aprobada'] || ! $diagnostico['documentacion_aprobada'] || ! $diagnostico['hay_producto_aprobado']) {
            return;
        }

        $proveedor->forceFill([
            'Id_Estado_Proveedor' => self::ESTADO_APROBADO,
            'Fecha_Aprobacion' => now(),
        ])->save();

        $this->notificarProveedorAprobado($proveedor);
    }

    /**
     * Veredicto final al cerrar la calificación de productos (ver
     * registrarCalificacionProductos, único llamador): si ya se cumplen
     * las 3 condiciones, aprueba (por si por algún camino no se había
     * disparado todavía). Si no se cumplen porque la ficha quedó
     * rechazada, o algún documento quedó rechazado, o ningún producto
     * quedó aprobado, rechaza formalmente al proveedor (hasta ahora
     * Id_Estado_Proveedor nunca pasaba a Rechazado de forma automática)
     * y le notifica por correo el detalle de qué se rechazó y por qué.
     */
    protected function resolverVeredictoFinal(Proveedor $proveedor, Usuario $admin): void
    {
        $proveedor->refresh();

        if ($proveedor->Id_Estado_Proveedor !== self::ESTADO_ASPIRANTE) {
            return;
        }

        $diagnostico = $this->diagnosticarCondicionesAprobado($proveedor);

        if ($diagnostico['ficha_aprobada'] && $diagnostico['documentacion_aprobada'] && $diagnostico['hay_producto_aprobado']) {
            $this->activarSiCorrespondeAprobado($proveedor);

            return;
        }

        $fichaRechazada = $proveedor->fresh('calificacionesCampos')->estadoGeneralCalificacionFicha() === 'Rechazado';

        $documentosRechazados = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Rechazado')
            ->with('tipoDocumento')
            ->get();

        // Ninguna de las 3 condiciones de rechazo explícito se cumple
        // (lo más probable: la ficha o la documentación todavía no
        // terminan de calificarse en algún flujo distinto al normal) ->
        // no corresponde tomar todavía una decisión final.
        if (! $fichaRechazada && $documentosRechazados->isEmpty() && $diagnostico['hay_producto_aprobado']) {
            return;
        }

        $camposRechazados = $proveedor->calificacionesCampos->where('Estado', 'Rechazado')->values();

        $productosRechazados = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Rechazado')
            ->get(['Nombre_Producto', 'Comentario_Calificacion']);

        app(ProveedorService::class)->cambiarEstado(
            $proveedor,
            self::ESTADO_RECHAZADO,
            'Rechazo automático al cerrar la calificación de productos: ficha, documentación o productos con observaciones sin resolver.',
            $admin->Id_Usuario
        );

        $this->notificarProveedorRechazado($proveedor, $camposRechazados, $documentosRechazados, $productosRechazados);
    }

    protected function notificarProveedorAprobado(Proveedor $proveedor): void
    {
        if (! $proveedor->Email) {
            return;
        }

        $nombreEmpresa = $proveedor->empresa?->Nombre_Comercial ?? $proveedor->empresa?->Razon_Social ?? 'Hanaska';
        $nombreProveedor = $proveedor->Nombre_Comercial ?: $proveedor->Razon_Social;

        (new AnonymousNotifiable())
            ->route('mail', $proveedor->Email)
            ->notify(new ProveedorAprobadoNotification($proveedor->Email, $nombreProveedor, $nombreEmpresa));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CalificacionCampoFicha>  $camposRechazados
     * @param  \Illuminate\Database\Eloquent\Collection<int, DocumentoProveedor>  $documentosRechazados
     * @param  \Illuminate\Database\Eloquent\Collection<int, Producto>  $productosRechazados
     */
    protected function notificarProveedorRechazado(
        Proveedor $proveedor,
        $camposRechazados,
        $documentosRechazados,
        $productosRechazados
    ): void {
        if (! $proveedor->Email) {
            return;
        }

        $nombreEmpresa = $proveedor->empresa?->Nombre_Comercial ?? $proveedor->empresa?->Razon_Social ?? 'Hanaska';
        $nombreProveedor = $proveedor->Nombre_Comercial ?: $proveedor->Razon_Social;

        $campos = $camposRechazados->map(fn (CalificacionCampoFicha $c) => [
            'nombre' => self::ETIQUETAS_CAMPOS_FICHA[$c->Nombre_Campo] ?? $c->Nombre_Campo,
            'motivo' => $c->Comentario,
        ])->all();

        $documentos = $documentosRechazados->map(fn (DocumentoProveedor $d) => [
            'nombre' => $d->tipoDocumento->Nombre_Documento ?? 'Documento',
            'motivo' => $d->Comentario_Calificacion,
        ])->all();

        $productos = $productosRechazados->map(fn (Producto $p) => [
            'nombre' => $p->Nombre_Producto,
            'motivo' => $p->Comentario_Calificacion,
        ])->all();

        (new AnonymousNotifiable())
            ->route('mail', $proveedor->Email)
            ->notify(new ProveedorRechazadoNotification($proveedor->Email, $nombreProveedor, $nombreEmpresa, $campos, $documentos, $productos));
    }

    protected function proveedorDeLaEmpresa(int $idEmpresaActiva, int $idProveedor): Proveedor
    {
        return Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->findOrFail($idProveedor);
    }

    /**
     * Solo Admin/Sistemas pueden calificar. Se resuelve el rol desde el
     * pivote Usuario_Empresa de la empresa activa (no desde un campo
     * fijo del usuario), porque el mismo usuario puede tener roles
     * distintos en distintas empresas. Un solo query con join a Rol
     * (antes eran 2 consultas separadas: pivote + Rol::find) -> esto se
     * ejecuta en CADA calificación, así que vale la pena que sea liviano.
     */
    protected function verificarEsAdmin(Usuario $usuario, int $idEmpresaActiva): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos pueden calificar proveedores.');
        }

        $nombreRol = DB::table('Usuario_Empresa')
            ->join('Rol', 'Rol.Id_Rol', '=', 'Usuario_Empresa.Id_Rol')
            ->where('Usuario_Empresa.Id_Usuario', $usuario->Id_Usuario)
            ->where('Usuario_Empresa.Id_Empresa', $idEmpresaActiva)
            ->where('Usuario_Empresa.Activo', true)
            ->value('Rol.Nombre_Rol');

        if (! in_array($nombreRol, ['Admin', 'Sistemas'], true)) {
            throw new AccessDeniedHttpException('No tienes permisos para calificar proveedores.');
        }
    }
}