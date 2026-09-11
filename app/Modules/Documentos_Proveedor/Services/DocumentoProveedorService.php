<?php

namespace App\Modules\Documentos_Proveedor\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Documentos_Proveedor\Models\TipoDocumentoClaseExcluida;
use App\Modules\Proveedores\Models\Proveedor;
use App\Shared\MueveArchivoAHistorico;
use App\Shared\SaneadorNombreArchivo;
use App\Shared\VerificaArchivoFisico;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    use MueveArchivoAHistorico, VerificaArchivoFisico;

    protected const DISCO = 'repositorio_proveedores';
    protected const DISCO_PLANTILLAS = 'plantillas';

   public function obtenerChecklist(Usuario $usuario, int $idEmpresaActiva): array
{
    $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);
    $esQuito = strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0;
    $idsExcluidosPorClase = $this->idsTiposDocumentoExcluidosPorClase($proveedor);

    $tipos = TipoDocumento::where('Activo', 1)
        // Los documentos "solo Quito" (ej. LUAE) ni siquiera se listan
        // para proveedores de otras ciudades, y los "excepto Quito"
        // (ej. Bomberos) no se listan para proveedores DE Quito -> no
        // solo se marcan como no obligatorios, desaparecen del
        // checklist por completo.
        ->where(function ($query) use ($esQuito) {
            $query->where('Requiere_Solo_Quito', 0)->where('Requiere_Excepto_Quito', 0);
            if ($esQuito) {
                $query->orWhere('Requiere_Solo_Quito', 1);
            } else {
                $query->orWhere('Requiere_Excepto_Quito', 1);
            }
        })
        // Documentos excluidos para alguna de las Clases de Proveedor
        // de este proveedor (ej. LUAE no se le pide a un Productor
        // Agrícola) tampoco aparecen en el checklist.
        ->when($idsExcluidosPorClase->isNotEmpty(), fn ($query) => $query->whereNotIn('Id_Tipo_Documento', $idsExcluidosPorClase))
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
        // true = hay (o hubo) documentos rechazados que el proveedor
        // todavía no confirmó haber corregido -> mientras esté en true,
        // los documentos no-Aprobados quedan editables aunque
        // "registrado" sea true. Se apaga con "Registrar documentación
        // actualizada" (ver confirmarCorreccionesDocumentos).
        'correcciones_pendientes' => (bool) $proveedor->Correcciones_Pendientes,
        'documentos' => $tipos->map(function (TipoDocumento $tipo) {
            return [
                'id_tipo_documento' => $tipo->Id_Tipo_Documento,
                'categoria' => $tipo->Categoria,
                'nombre_documento' => $tipo->Nombre_Documento,
                'obligatorio' => (bool) $tipo->Obligatorio,
                'permite_multiples' => (bool) $tipo->Permite_Multiples,
                'requiere_fecha_caducidad' => (bool) $tipo->Requiere_Fecha_Caducidad,
                // true = hay una plantilla .docx en blanco descargable
                // para este tipo (ver descargarPlantilla()) -> el front
                // muestra un link "Descargar plantilla" junto al control
                // de carga cuando esto es true.
                'tiene_plantilla' => (bool) $tipo->Ruta_Plantilla,
                // Solo el Certificado bancario: además del PDF, el
                // proveedor tiene que declarar banco / tipo de cuenta /
                // nro de cuenta, porque eso es lo que se postea a la
                // Ficha de Bancos de Business Central (del PDF no se
                // puede extraer). Se marca por Codigo_Archivo y no por
                // Id_Tipo_Documento para no clavar un id en el front.
                'requiere_datos_bancarios' => $tipo->Codigo_Archivo === 'CBANCARIO',
                'documentos' => $tipo->documentosProveedor->map(fn (DocumentoProveedor $doc) => [
                    'id_documento_proveedor' => $doc->Id_Documento_Proveedor,
                    'nombre_original' => $doc->archivo->Nombre_Original,
                    'fecha_caducidad' => $doc->Fecha_Caducidad?->toDateString(),
                    // true = vence en 30 días o menos (o ya venció) ->
                    // usado para que un proveedor YA APROBADO pueda
                    // reemplazar ESTE documento puntual aunque el resto
                    // de su documentación ya esté aprobada y bloqueada
                    // (ver ChecklistDocumentos.tsx en el frontend).
                    'proximo_a_vencer' => $doc->Fecha_Caducidad !== null && $doc->Fecha_Caducidad->lte(now()->addDays(30)),
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

        // Misma excepción que reemplazarDocumento/borrarDocumento: si
        // hay correcciones pendientes de confirmar, se puede seguir
        // subiendo aunque la documentación ya esté registrada -> hace
        // falta acá específicamente para el caso de un tipo
        // "Permite_Multiples" que se quedó en 0 documentos (ej. se
        // borró el único que había, que estaba rechazado): no hay un
        // documento existente que reemplazar, hay que subir uno nuevo
        // desde cero, y ese flujo pasa por ESTE método, no por
        // reemplazarDocumento.
        if ($proveedor->Fecha_Registro_Documentacion !== null && ! $proveedor->Correcciones_Pendientes) {
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

        return DB::transaction(function () use ($usuario, $proveedor, $tipo, $archivo, $fechaCaducidad, $nombreDocumento) {
            $registroArchivo = $this->guardarArchivoFisico($usuario, $proveedor, $tipo, $archivo, $nombreDocumento);

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
            ->with('tipoDocumento', 'archivo')
            ->findOrFail($idDocumentoProveedor);

        // La documentación bloqueada (ya registrada) normalmente no se
        // puede tocar -> EXCEPTO en 2 casos:
        // 1) Mientras haya correcciones pendientes de confirmar (el admin
        //    rechazó algo y el proveedor no dijo "ya terminé de
        //    corregir" todavía) Y este archivo puntual no esté ya
        //    Aprobado -> cubre el rechazo original y el caso de haberse
        //    equivocado de archivo al reemplazar.
        // 2) El documento está a 30 días o menos de vencer (o ya venció)
        //    -> un proveedor YA APROBADO puede renovarlo aunque el resto
        //    de su documentación siga aprobada y bloqueada. Vuelve a
        //    quedar pendiente de calificar (ver más abajo, el nuevo
        //    registro nace sin Estado_Calificacion).
        $proximoAVencer = $documentoActual->Fecha_Caducidad !== null
            && $documentoActual->Fecha_Caducidad->lte(now()->addDays(30));

        $puedeEditarAunqueEsteRegistrada =
            ($proveedor->Correcciones_Pendientes && $documentoActual->Estado_Calificacion !== 'Aprobado')
            || $proximoAVencer;

        if ($proveedor->Fecha_Registro_Documentacion !== null && ! $puedeEditarAunqueEsteRegistrada) {
            throw ValidationException::withMessages([
                'archivo' => ['Este documento ya fue aprobado y no se puede modificar.'],
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

        $estabaRechazado = $documentoActual->Estado_Calificacion === 'Rechazado';

        return DB::transaction(function () use ($usuario, $proveedor, $tipo, $documentoActual, $archivo, $fechaCaducidad, $nombreDocumento, $estabaRechazado) {
            $registroArchivo = $this->guardarArchivoFisico($usuario, $proveedor, $tipo, $archivo, $nombreDocumento);

            // Se desactiva ESTE documento puntual -> los demás archivos
            // del mismo tipo (si Permite_Multiples) quedan intactos.
            // El archivo físico viejo:
            // - si estaba Rechazado -> se archiva en "historico/" (fue
            //   una corrección real de algo que un admin juzgó).
            // - si nunca se llegó a revisar (Pendiente/null, típico de
            //   corregir un error de tipeo antes de registrar nada) ->
            //   se borra directo, no aporta nada guardarlo. Cada fila de
            //   Documento_Proveedor tiene su propio Estado_Calificacion
            //   (arranca en null), así que este chequeo simple ya
            //   alcanza acá -> a diferencia de Productos, no hace falta
            //   comparar fechas.
            $rutaHistorico = $this->archivarOEliminarSegunEstado(
                self::DISCO,
                $documentoActual->archivo?->Ruta_Almacenamiento,
                $documentoActual->Estado_Calificacion
            );

            if ($rutaHistorico) {
                $documentoActual->archivo->update(['Ruta_Almacenamiento' => $rutaHistorico]);
            }

            $documentoActual->update(['Activo' => 0]);

            $nuevoDocumento = DocumentoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
                'Id_Archivo' => $registroArchivo->Id_Archivo,
                'Fecha_Caducidad' => $fechaCaducidad,
                'Estado' => 'Vigente',
                'Activo' => 1,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            // Si estaba rechazado y el admin ya había "Registrado" la
            // calificación de documentos completa, esa confirmación
            // queda obsoleta (hay un documento nuevo sin calificar) ->
            // se reabre para que vuelva a aparecer en su cola de revisión.
            if ($estabaRechazado && $proveedor->Fecha_Registro_Calificacion_Documentos !== null) {
                $proveedor->forceFill(['Fecha_Registro_Calificacion_Documentos' => null])->save();
            }

            return $nuevoDocumento->load('archivo', 'tipoDocumento');
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

        $documento = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->with(['archivo', 'tipoDocumento'])
            ->findOrFail($idDocumentoProveedor);

        // Los documentos OBLIGATORIOS nunca se pueden borrar del todo,
        // solo reemplazar -> esta regla vivía solo en el frontend (el
        // botón "Borrar" se ocultaba), pero nada impedía pegarle
        // directo a este endpoint. Se refuerza acá también.
        if ($documento->tipoDocumento->Obligatorio) {
            throw ValidationException::withMessages([
                'archivo' => ['Este documento es obligatorio, no se puede eliminar. Puede reemplazarlo por otro.'],
            ]);
        }

        // Misma regla que reemplazarDocumento: se puede borrar mientras
        // haya correcciones pendientes de confirmar y no esté Aprobado.
        $puedeEditarAunqueEsteRegistrada = $proveedor->Correcciones_Pendientes && $documento->Estado_Calificacion !== 'Aprobado';

        if ($proveedor->Fecha_Registro_Documentacion !== null && ! $puedeEditarAunqueEsteRegistrada) {
            throw ValidationException::withMessages([
                'archivo' => ['Este documento ya fue aprobado y no se puede modificar.'],
            ]);
        }

        $estabaRechazado = $documento->Estado_Calificacion === 'Rechazado';

        DB::transaction(function () use ($documento, $proveedor, $estabaRechazado) {
            $rutaHistorico = $this->archivarOEliminarSegunEstado(
                self::DISCO,
                $documento->archivo?->Ruta_Almacenamiento,
                $documento->Estado_Calificacion
            );

            if ($rutaHistorico) {
                $documento->archivo->update(['Ruta_Almacenamiento' => $rutaHistorico]);
            }

            $documento->update(['Activo' => 0]);

            if ($estabaRechazado && $proveedor->Fecha_Registro_Calificacion_Documentos !== null) {
                $proveedor->forceFill(['Fecha_Registro_Calificacion_Documentos' => null])->save();
            }
        });
    }

    /**
     * Sube el archivo físico al disco y crea su registro Archivo. Usado
     * tanto por subirDocumento (documento nuevo) como por
     * reemplazarDocumento (reemplazo puntual de uno existente).
     *
     * Nombre_Original (lo que ve el proveedor/admin en el front) y el
     * nombre físico en disco son EXACTAMENTE el mismo string -> antes se
     * generaban por separado y terminaban distintos, lo que confundía
     * si alguien tenía que buscar el archivo a mano en el repositorio
     * después de verlo en la app.
     *
     * $nombreDocumento: el nombre que el proveedor escribió a mano, solo
     * para tipos "Permite_Multiples" (ej. "HACCP 2026") -> sin esto se
     * perdería la única forma de distinguir varios archivos del mismo
     * tipo entre sí. Para tipos de un solo archivo posible, se ignora
     * (el código del tipo ya identifica de qué se trata).
     */
    protected function guardarArchivoFisico(Usuario $usuario, Proveedor $proveedor, TipoDocumento $tipo, UploadedFile $archivo, ?string $nombreDocumento): Archivo
    {
        $registroArchivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            // Arranca vacío -> se completa más abajo, junto con
            // Ruta_Almacenamiento, una vez que se conoce Id_Archivo
            // (hace falta para que el nombre sea único).
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

        $extension = $archivo->getClientOriginalExtension() ?: 'pdf';
        // /var/repositorio/proveedores/{RUC}/{Id_Empresa}_{Empresa}/documentacion/{tipo}/{CODIGO}[_{NombrePersonalizado}]_{RazonSocial}_{IdArchivo}.pdf
        $carpeta = $this->carpetaProveedor($proveedor).'/documentacion/'.$tipo->Carpeta_Slug;

        $codigo = SaneadorNombreArchivo::sanear($tipo->Codigo_Archivo ?? $tipo->Carpeta_Slug, 'DOC');
        $nombrePersonalizado = $tipo->Permite_Multiples ? SaneadorNombreArchivo::sanear($nombreDocumento) : null;
        $razonSocial = SaneadorNombreArchivo::sanear($proveedor->Razon_Social);

        $partes = array_filter([$codigo, $nombrePersonalizado, $razonSocial, (string) $registroArchivo->Id_Archivo]);
        $nombreFisico = implode('_', $partes).".{$extension}";

        Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombreFisico);

        $registroArchivo->update([
            'Nombre_Original' => $nombreFisico,
            'Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}",
        ]);

        return $registroArchivo;
    }

    /**
     * proveedores/{RUC}/{Id_Empresa}_{NombreEmpresa} -> el mismo RUC
     * puede estar registrado como proveedor en más de una empresa
     * (son registros de Proveedor distintos) -> sin la subcarpeta por
     * empresa, los documentos de una empresa se mezclarían/pisarían con
     * los de la otra.
     */
    protected function carpetaProveedor(Proveedor $proveedor): string
    {
        $ruc = SaneadorNombreArchivo::sanear($proveedor->Ruc, "sin-ruc-{$proveedor->Id_Proveedor}");
        $empresa = SaneadorNombreArchivo::sanear($proveedor->empresa?->Nombre_Comercial ?? $proveedor->empresa?->Razon_Social);

        return "proveedores/{$ruc}/{$proveedor->Id_Empresa}_{$empresa}";
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
        $idsExcluidosPorClase = $this->idsTiposDocumentoExcluidosPorClase($proveedor);

        $tiposObligatorios = TipoDocumento::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->where(function ($query) use ($esQuito) {
                $query->where('Requiere_Solo_Quito', 0)->where('Requiere_Excepto_Quito', 0);
                if ($esQuito) {
                    $query->orWhere('Requiere_Solo_Quito', 1);
                } else {
                    $query->orWhere('Requiere_Excepto_Quito', 1);
                }
            })
            ->when($idsExcluidosPorClase->isNotEmpty(), fn ($query) => $query->whereNotIn('Id_Tipo_Documento', $idsExcluidosPorClase))
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

    /**
     * "Registrar documentación actualizada": el proveedor confirma que ya
     * corrigió todo lo que el admin había rechazado. Exige que no quede
     * NINGÚN documento activo en estado "Rechazado" (si queda alguno sin
     * corregir, se rechaza indicando cuántos faltan) -> recién ahí apaga
     * Correcciones_Pendientes, y todo vuelve a quedar bloqueado (solo
     * lectura) hasta que el admin dé su retroalimentación de nuevo.
     */
    public function confirmarCorrecciones(Usuario $usuario, int $idEmpresaActiva): void
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if (! $proveedor->Correcciones_Pendientes) {
            throw ValidationException::withMessages([
                'documentacion' => ['No tienes correcciones pendientes de confirmar.'],
            ]);
        }

        $pendientes = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Rechazado')
            ->count();

        if ($pendientes > 0) {
            throw ValidationException::withMessages([
                'documentacion' => ["Todavía te falta corregir {$pendientes} documento(s) rechazado(s)."],
            ]);
        }

        $proveedor->forceFill(['Correcciones_Pendientes' => false])->save();
    }

    public function descargar(Usuario $usuario, int $idEmpresaActiva, int $idDocumentoProveedor)
{
    $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

    $documento = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
        ->with('archivo')
        ->findOrFail($idDocumentoProveedor);

    $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

    $this->verificarArchivoEntregable($rutaCompleta);

    return response()->download($rutaCompleta, $documento->archivo->Nombre_Original);
}

    /**
     * Descarga la plantilla .docx en blanco de un Tipo_Documento (ej.
     * "Check list autoevaluación de proveedores", "Carta de Garantia")
     * para que el proveedor la llene y luego suba el archivo completo
     * por el flujo normal de subirDocumento(). Solo valida que el
     * usuario sea un Proveedor de la empresa activa (no hace falta que
     * la documentación esté en ningún estado en particular -> a
     * diferencia de subir/reemplazar, descargar la plantilla siempre
     * está permitido).
     */
    public function descargarPlantilla(Usuario $usuario, int $idEmpresaActiva, int $idTipoDocumento)
    {
        $this->miProveedor($usuario, $idEmpresaActiva);

        $tipo = TipoDocumento::where('Activo', 1)->findOrFail($idTipoDocumento);

        if (! $tipo->Ruta_Plantilla) {
            throw new NotFoundHttpException('Este documento no tiene una plantilla disponible para descargar.');
        }

        if (! Storage::disk(self::DISCO_PLANTILLAS)->exists($tipo->Ruta_Plantilla)) {
            throw new NotFoundHttpException('La plantilla no se encuentra en el servidor.');
        }

        $rutaCompleta = Storage::disk(self::DISCO_PLANTILLAS)->path($tipo->Ruta_Plantilla);
        $nombreDescarga = basename($tipo->Ruta_Plantilla);

        return response()->download($rutaCompleta, $nombreDescarga);
    }

    /**
     * Tipos de documento OBLIGATORIOS que le corresponden a este proveedor,
     * ya filtrados por las dos reglas del catálogo: los "solo Quito" /
     * "excepto Quito" según su ciudad, y los excluidos por alguna de sus
     * Clases de Proveedor.
     *
     * Existe como método público porque ese filtro se necesita en cuatro
     * lugares distintos (el checklist del proveedor, el del admin,
     * registrar() y ahora la calificación global). Estaba copiado en cada
     * uno: si mañana entra una regla nueva de exclusión y se actualiza una
     * sola copia, cada pantalla contesta una cosa distinta sobre el mismo
     * proveedor.
     */
    public function tiposObligatoriosAplicables(Proveedor $proveedor)
    {
        $esQuito = strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0;
        $idsExcluidosPorClase = $this->idsTiposDocumentoExcluidosPorClase($proveedor);

        return TipoDocumento::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->where(function ($query) use ($esQuito) {
                $query->where('Requiere_Solo_Quito', 0)->where('Requiere_Excepto_Quito', 0);
                if ($esQuito) {
                    $query->orWhere('Requiere_Solo_Quito', 1);
                } else {
                    $query->orWhere('Requiere_Excepto_Quito', 1);
                }
            })
            ->when($idsExcluidosPorClase->isNotEmpty(), fn ($query) => $query->whereNotIn('Id_Tipo_Documento', $idsExcluidosPorClase))
            ->get();
    }

    /**
     * Ids de Tipo_Documento que NO se le deben pedir a este proveedor
     * por alguna de sus Clases de Proveedor (ej. LUAE excluido para
     * "Productor Agrícola") -> ver Tipo_Documento_Clase_Excluida. Un
     * proveedor puede tener varias clases (Sección 2 de la Ficha es
     * multi-select); alcanza con que UNA de ellas esté excluida de un
     * tipo para que ese documento no se le pida.
     */
    protected function idsTiposDocumentoExcluidosPorClase(Proveedor $proveedor): \Illuminate\Support\Collection
    {
        $idsClases = $proveedor->clases()->pluck('Clase_Proveedor.Id_Clase_Proveedor');

        if ($idsClases->isEmpty()) {
            return collect();
        }

        return TipoDocumentoClaseExcluida::where('Activo', 1)
            ->whereIn('Id_Clase_Proveedor', $idsClases)
            ->pluck('Id_Tipo_Documento');
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