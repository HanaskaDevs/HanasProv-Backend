<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Models\CalificacionCampoFicha;
use App\Modules\Proveedores\Models\Proveedor;
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
     * Califica UN campo puntual de la ficha (o una de las 2 secciones de
     * selección múltiple, tratadas como bloque). Upsert: si ya existía
     * una calificación para ese campo, se actualiza en vez de acumular
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