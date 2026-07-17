<?php

namespace App\Modules\Documentos_Proveedor\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Documentación de proveedor: catálogo fijo de 12 tipos de documento
 * (Tipo_Documento), con historial conservado — nunca se borra un
 * Documento_Proveedor, solo se marca Activo = 0 al reemplazarlo.
 */
class DocumentoProveedorService
{
    protected const DISCO = 'repositorio_proveedores';

   public function obtenerChecklist(Usuario $usuario, int $idEmpresaActiva): array
{
    $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);
    $esQuito = strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0;

    $tipos = TipoDocumento::where('Activo', 1)
        // Los documentos "solo Quito" (ej. LUAE) ni siquiera se listan
        // para proveedores de otras ciudades -> no solo se marcan como
        // no obligatorios, desaparecen del checklist por completo.
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
        // Una vez registrada, el checklist queda 100% de solo lectura:
        // el front usa esto para ocultar los botones de cargar/reemplazar
        // y el back lo vuelve a validar en subirDocumento() por si acaso.
        'registrado' => $proveedor->Fecha_Registro_Documentacion !== null,
        'fecha_registro' => $proveedor->Fecha_Registro_Documentacion?->toIso8601String(),
        'documentos' => $tipos->map(function (TipoDocumento $tipo) {
            return [
                'id_tipo_documento' => $tipo->Id_Tipo_Documento,
                'categoria' => $tipo->Categoria,
                'nombre_documento' => $tipo->Nombre_Documento,
                'obligatorio' => (bool) $tipo->Obligatorio,
                'permite_multiples' => (bool) $tipo->Permite_Multiples,
                'requiere_fecha_caducidad' => (bool) $tipo->Requiere_Fecha_Caducidad,
                'documentos' => $tipo->documentosProveedor->map(fn (DocumentoProveedor $doc) => [
                    'id_documento_proveedor' => $doc->Id_Documento_Proveedor,
                    'nombre_original' => $doc->archivo->Nombre_Original,
                    'fecha_caducidad' => $doc->Fecha_Caducidad?->toDateString(),
                    'estado' => $doc->Estado,
                    'fecha_creacion' => $doc->Fecha_Creacion,
                    // Lo que calificó el admin sobre ESTE archivo puntual.
                    // El proveedor necesita verlo para saber si algo fue
                    // rechazado y por qué (comentario_calificacion).
                    'estado_calificacion' => $doc->Estado_Calificacion,
                    'comentario_calificacion' => $doc->Comentario_Calificacion,
                    'fecha_calificacion' => $doc->Fecha_Calificacion?->toIso8601String(),
                ])->values(),
            ];
        })->values(),
    ];
}

    public function subirDocumento(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idTipoDocumento,
        UploadedFile $archivo,
        ?string $fechaCaducidad,
        ?string $nombreDocumento = null
    ): DocumentoProveedor {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ($proveedor->Fecha_Registro_Documentacion !== null) {
            throw ValidationException::withMessages([
                'archivo' => ['Tu documentación ya fue registrada y no se puede modificar.'],
            ]);
        }

        $tipo = TipoDocumento::where('Activo', 1)->findOrFail($idTipoDocumento);

        if ($tipo->Requiere_Fecha_Caducidad && ! $fechaCaducidad) {
            throw ValidationException::withMessages([
                'fecha_caducidad' => ['Este documento requiere fecha de caducidad.'],
            ]);
        }

        // Tipos que permiten varios archivos (ej. "Certificaciones de
        // calidad"): el proveedor tiene que decirnos CÓMO se llama cada uno
        // (ej. "HACCP 2026"), porque puede haber varios y no se
        // distinguirían entre sí. Tipos de un solo archivo (ej. "RUC"): el
        // nombre siempre es el mismo, el del propio Tipo_Documento -> no
        // hace falta preguntar nada, se ignora el nombre real del PDF.
        if ($tipo->Permite_Multiples && ! $nombreDocumento) {
            throw ValidationException::withMessages([
                'nombre_documento' => ['Indica el nombre de este documento.'],
            ]);
        }

        $nombreFinal = $this->nombreArchivoFinal($tipo, $proveedor, $archivo, $nombreDocumento);

        return DB::transaction(function () use ($usuario, $proveedor, $tipo, $archivo, $fechaCaducidad, $nombreFinal) {
            $registroArchivo = $this->guardarArchivoFisico($usuario, $proveedor, $tipo, $archivo, $nombreFinal);

            if (! $tipo->Permite_Multiples) {
                DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
                    ->where('Id_Tipo_Documento', $tipo->Id_Tipo_Documento)
                    ->where('Activo', 1)
                    ->update(['Activo' => 0]);
            }

            return DocumentoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
                'Id_Archivo' => $registroArchivo->Id_Archivo,
                'Fecha_Caducidad' => $fechaCaducidad,
                'Estado' => 'Vigente',
                'Activo' => 1,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ])->load('archivo', 'tipoDocumento');
        });
    }

    /**
     * Reemplaza UN documento puntual (por su Id_Documento_Proveedor), sin
     * tocar los demás archivos del mismo tipo -> es lo que necesitan los
     * tipos con Permite_Multiples (ej. "Certificaciones de calidad"),
     * donde "Reemplazar" debe afectar solo a ese archivo específico y no
     * borrar/ocultar el resto que el proveedor ya había cargado.
     */
    public function reemplazarDocumento(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idDocumentoProveedor,
        UploadedFile $archivo,
        ?string $fechaCaducidad,
        ?string $nombreDocumento = null
    ): DocumentoProveedor {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $documentoActual = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->with('tipoDocumento')
            ->findOrFail($idDocumentoProveedor);

        // La documentación bloqueada (ya registrada) normalmente no se
        // puede tocar -> EXCEPTO si el admin rechazó justo ESTE archivo:
        // ahí sí se deja reemplazarlo puntualmente, para que el proveedor
        // pueda corregir lo que le señalaron sin tener que reabrir todo
        // el proceso de documentación.
        $puedeCorregirRechazo = $documentoActual->Estado_Calificacion === 'Rechazado';

        if ($proveedor->Fecha_Registro_Documentacion !== null && ! $puedeCorregirRechazo) {
            throw ValidationException::withMessages([
                'archivo' => ['Tu documentación ya fue registrada y no se puede modificar.'],
            ]);
        }

        $tipo = $documentoActual->tipoDocumento;

        if ($tipo->Requiere_Fecha_Caducidad && ! $fechaCaducidad) {
            throw ValidationException::withMessages([
                'fecha_caducidad' => ['Este documento requiere fecha de caducidad.'],
            ]);
        }

        if ($tipo->Permite_Multiples && ! $nombreDocumento) {
            throw ValidationException::withMessages([
                'nombre_documento' => ['Indica el nombre de este documento.'],
            ]);
        }

        $nombreFinal = $this->nombreArchivoFinal($tipo, $proveedor, $archivo, $nombreDocumento);

        return DB::transaction(function () use ($usuario, $proveedor, $tipo, $documentoActual, $archivo, $fechaCaducidad, $nombreFinal) {
            $registroArchivo = $this->guardarArchivoFisico($usuario, $proveedor, $tipo, $archivo, $nombreFinal);

            // Solo se desactiva ESTE documento puntual -> los demás archivos
            // del mismo tipo (si Permite_Multiples) quedan intactos.
            $documentoActual->update(['Activo' => 0]);

            return DocumentoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
                'Id_Archivo' => $registroArchivo->Id_Archivo,
                'Fecha_Caducidad' => $fechaCaducidad,
                'Estado' => 'Vigente',
                'Activo' => 1,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ])->load('archivo', 'tipoDocumento');
        });
    }

    /**
     * Borra (soft-delete, Activo = 0) un documento puntual. No borra el
     * archivo físico ni la fila -> se conserva como historial, igual que
     * el resto del módulo, solo deja de aparecer en el checklist.
     */
    public function borrarDocumento(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProveedor): void
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ($proveedor->Fecha_Registro_Documentacion !== null) {
            throw ValidationException::withMessages([
                'archivo' => ['Tu documentación ya fue registrada y no se puede modificar.'],
            ]);
        }

        $documento = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->findOrFail($idDocumentoProveedor);

        $documento->update(['Activo' => 0]);
    }

    /**
     * Decide con qué nombre se guarda/muestra el documento:
     * - Permite_Multiples: el nombre que escribió el proveedor (puede haber
     *   varios del mismo tipo, tienen que distinguirse entre sí).
     * - Un solo archivo posible: siempre el Nombre_Documento del catálogo
     *   (ej. "RUC"), sin importar cómo se llamaba el PDF original.
     * A eso se le agrega SIEMPRE la Razón Social del proveedor y la fecha
     * de carga, para que el archivo sea identificable por sí solo si
     * alguien lo descarga suelto (ej. "RUC_CANODROS_CL_20260714.pdf").
     */
    protected function nombreArchivoFinal(TipoDocumento $tipo, Proveedor $proveedor, UploadedFile $archivo, ?string $nombreDocumento): string
    {
        $extension = $archivo->getClientOriginalExtension() ?: 'pdf';
        $base = $tipo->Permite_Multiples ? trim($nombreDocumento) : $tipo->Nombre_Documento;

        $partes = [
            $this->sanitizarNombreArchivo($base),
            $this->sanitizarNombreArchivo($proveedor->Razon_Social),
            now()->format('Ymd'),
        ];

        return implode('_', array_filter($partes)).".{$extension}";
    }

    /**
     * Deja el texto seguro para usarlo como nombre de archivo: sin tildes/ñ
     * (transliterado a ASCII), sin caracteres que rompan rutas o headers
     * HTTP (/, \, comillas, etc.), y espacios colapsados en "_".
     */
    protected function sanitizarNombreArchivo(?string $texto): string
    {
        if (! $texto) {
            return '';
        }

        $texto = Str::ascii($texto);
        $texto = preg_replace('/[^A-Za-z0-9 _-]/', '', $texto) ?? $texto;
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);

        return str_replace(' ', '_', $texto);
    }

    /**
     * Sube el archivo físico al disco y crea su registro Archivo. Usado
     * tanto por subirDocumento (documento nuevo) como por
     * reemplazarDocumento (reemplazo puntual de uno existente).
     * $nombreFinal es el nombre "de negocio" con el que se muestra/guarda
     * el documento (ver nombreArchivoFinal) -> el nombre real que traía el
     * PDF del usuario ya no se usa para nada, ni para mostrar ni guardar.
     */
    protected function guardarArchivoFisico(Usuario $usuario, Proveedor $proveedor, TipoDocumento $tipo, UploadedFile $archivo, string $nombreFinal): Archivo
    {
        $registroArchivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => $nombreFinal,
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
        $carpeta = "{$proveedor->Id_Empresa}/{$proveedor->Id_Proveedor}/{$tipo->Carpeta_Slug}";
        $nombreFisico = "{$registroArchivo->Id_Archivo}.{$extension}";

        Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombreFisico);

        $registroArchivo->update([
            'Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}",
        ]);

        return $registroArchivo;
    }

    /**
     * Bloquea el checklist de documentación del proveedor: desde acá ya
     * no puede subir ni reemplazar nada, solo ver lo que ya cargó.
     * Valida primero que TODOS los tipos obligatorios (según su ciudad)
     * tengan al menos un documento activo -> si falta alguno, se rechaza
     * con el detalle de qué falta (esto también protege contra que
     * alguien llame al endpoint directo sin pasar por el botón del front).
     */
    public function registrar(Usuario $usuario, int $idEmpresaActiva): void
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ($proveedor->Fecha_Registro_Documentacion !== null) {
            throw ValidationException::withMessages([
                'documentacion' => ['Tu documentación ya estaba registrada.'],
            ]);
        }

        $esQuito = strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0;

        $tiposObligatorios = TipoDocumento::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->where(function ($query) use ($esQuito) {
                $query->where('Requiere_Solo_Quito', 0);
                if ($esQuito) {
                    $query->orWhere('Requiere_Solo_Quito', 1);
                }
            })
            ->withCount(['documentosProveedor' => function ($query) use ($proveedor) {
                $query->where('Id_Proveedor', $proveedor->Id_Proveedor)->where('Activo', 1);
            }])
            ->get();

        $faltantes = $tiposObligatorios->where('documentos_proveedor_count', 0)->pluck('Nombre_Documento');

        if ($faltantes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documentacion' => ['Todavía falta cargar: '.$faltantes->implode(', ').'.'],
            ]);
        }

        $proveedor->update(['Fecha_Registro_Documentacion' => now()]);
    }

    public function descargar(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProveedor)
{
    $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

    $documento = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
        ->with('archivo')
        ->findOrFail($idDocumentoProveedor);

    $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

    if (! is_file($rutaCompleta)) {
        throw new NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
    }

    return response()->download($rutaCompleta, $documento->archivo->Nombre_Original);
}

    /**
     * Resuelve el Proveedor del usuario autenticado QUE PERTENECE A LA
     * EMPRESA ACTIVA de su sesión -> un mismo usuario externo puede tener
     * Proveedores distintos en distintas empresas (vía Usuario_Proveedor),
     * la documentación de cada empresa es completamente independiente.
     */
    protected function miProveedor(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) gestionan su propia documentación.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresaActiva)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado a la empresa activa.');
        }

        return $proveedor;
    }
}