<?php

namespace App\Modules\Catalogos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TipoDocumentoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(TipoDocumento::orderBy('Categoria')->orderBy('Nombre_Documento')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $this->validarDatos($request);

        $tipo = TipoDocumento::create([
            ...$this->mapearDatos($datos),
            'Activo' => true,
        ]);

        return response()->json($tipo, 201);
    }

    public function update(Request $request, TipoDocumento $tipoDocumento): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $this->validarDatos($request, $tipoDocumento->Id_Tipo_Documento);

        $tipoDocumento->update($this->mapearDatos($datos));

        return response()->json($tipoDocumento);
    }

    public function destroy(Request $request, TipoDocumento $tipoDocumento): JsonResponse
    {
        $this->verificarSistemas($request);

        // Soft-delete a propósito: los documentos ya subidos de este
        // tipo (Documento_Proveedor.Id_Tipo_Documento) no deben quedar
        // huérfanos ni perder su referencia histórica.
        $tipoDocumento->update(['Activo' => false]);

        return response()->json(['message' => 'Tipo de documento inactivado correctamente.']);
    }

    public function activar(Request $request, TipoDocumento $tipoDocumento): JsonResponse
    {
        $this->verificarSistemas($request);

        $tipoDocumento->update(['Activo' => true]);

        return response()->json($tipoDocumento);
    }

    protected function validarDatos(Request $request, ?int $idActual = null): array
    {
        return $request->validate([
            'categoria' => ['required', 'string', 'max:50'],
            'nombre_documento' => [
                'required', 'string', 'max:150',
                Rule::unique('Tipo_Documento', 'Nombre_Documento')->ignore($idActual, 'Id_Tipo_Documento'),
            ],
            'carpeta_slug' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('Tipo_Documento', 'Carpeta_Slug')->ignore($idActual, 'Id_Tipo_Documento'),
            ],
            'codigo_archivo' => ['nullable', 'string', 'max:15'],
            'obligatorio' => ['required', 'boolean'],
            'permite_multiples' => ['required', 'boolean'],
            'requiere_fecha_caducidad' => ['required', 'boolean'],
            'requiere_solo_quito' => ['required', 'boolean'],
        ]);
    }

    protected function mapearDatos(array $datos): array
    {
        return [
            'Categoria' => $datos['categoria'],
            'Nombre_Documento' => $datos['nombre_documento'],
            'Carpeta_Slug' => $datos['carpeta_slug'],
            'Codigo_Archivo' => $datos['codigo_archivo'] ?? null,
            'Obligatorio' => $datos['obligatorio'],
            'Permite_Multiples' => $datos['permite_multiples'],
            'Requiere_Fecha_Caducidad' => $datos['requiere_fecha_caducidad'],
            'Requiere_Solo_Quito' => $datos['requiere_solo_quito'],
        ];
    }

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar este catálogo.');
        }
    }
}