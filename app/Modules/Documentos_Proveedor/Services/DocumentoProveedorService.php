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

   public function obtenerChecklist(Usuario $usuario): array
{
    $proveedor = $this->miProveedor($usuario);

    $tipos = TipoDocumento::where('Activo', 1)
        ->with(['documentosProveedor' => function ($query) use ($proveedor) {
            $query->where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->with('archivo');
        }])
        ->orderBy('Categoria')
        ->get();

    return $tipos->map(function (TipoDocumento $tipo) use ($proveedor) {
        $obligatorio = $tipo->Requiere_Solo_Quito
            ? strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0
            : $tipo->Obligatorio;

        return [
            'id_tipo_documento' => $tipo->Id_Tipo_Documento,
            'categoria' => $tipo->Categoria,
            'nombre_documento' => $tipo->Nombre_Documento,
            'obligatorio' => $obligatorio,
            'permite_multiples' => $tipo->Permite_Multiples,
            'requiere_fecha_caducidad' => $tipo->Requiere_Fecha_Caducidad,
            'documentos' => $tipo->documentosProveedor->map(fn (DocumentoProveedor $doc) => [
                'id_documento_proveedor' => $doc->Id_Documento_Proveedor,
                'nombre_original' => $doc->archivo->Nombre_Original,
                'fecha_caducidad' => $doc->Fecha_Caducidad?->toDateString(),
                'estado' => $doc->Estado,
                'fecha_creacion' => $doc->Fecha_Creacion,
            ])->values(),
        ];
    })->toArray();
}

    public function subirDocumento(
        Usuario $usuario,
        int $idTipoDocumento,
        UploadedFile $archivo,
        ?string $fechaCaducidad
    ): DocumentoProveedor {
        $proveedor = $this->miProveedor($usuario);
        $tipo = TipoDocumento::where('Activo', 1)->findOrFail($idTipoDocumento);

        if ($tipo->Requiere_Fecha_Caducidad && ! $fechaCaducidad) {
            throw ValidationException::withMessages([
                'fecha_caducidad' => ['Este documento requiere fecha de caducidad.'],
            ]);
        }

        return DB::transaction(function () use ($usuario, $proveedor, $tipo, $archivo, $fechaCaducidad) {
            $registroArchivo = Archivo::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
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
            $carpeta = "{$proveedor->Id_Empresa}/{$proveedor->Id_Proveedor}/{$tipo->Carpeta_Slug}";
            $nombreFisico = "{$registroArchivo->Id_Archivo}.{$extension}";

            Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombreFisico);

            $registroArchivo->update([
                'Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}",
            ]);

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

    public function descargar(Usuario $usuario, int $idDocumentoProveedor)
{
    $proveedor = $this->miProveedor($usuario);

    $documento = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
        ->with('archivo')
        ->findOrFail($idDocumentoProveedor);

    $rutaCompleta = Storage::disk(self::DISCO)->path($documento->archivo->Ruta_Almacenamiento);

    if (! is_file($rutaCompleta)) {
        throw new NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
    }

    return response()->download($rutaCompleta, $documento->archivo->Nombre_Original);
}

    protected function miProveedor(Usuario $usuario): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) gestionan su propia documentación.');
        }

        if (! $usuario->Id_Proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado.');
        }

        return Proveedor::findOrFail($usuario->Id_Proveedor);
    }
}