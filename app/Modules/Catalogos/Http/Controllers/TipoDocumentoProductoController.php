<?php

namespace App\Modules\Catalogos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TipoDocumentoProductoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(TipoDocumentoProducto::orderBy('Nombre_Documento')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $this->validarDatos($request);

        $tipo = TipoDocumentoProducto::create([
            'Nombre_Documento' => $datos['nombre_documento'],
            'Carpeta_Slug' => $datos['carpeta_slug'],
            'Codigo_Archivo' => $datos['codigo_archivo'] ?? null,
            'Obligatorio' => $datos['obligatorio'],
            'Activo' => true,
        ]);

        return response()->json($tipo, 201);
    }

    public function update(Request $request, TipoDocumentoProducto $tipoDocumentoProducto): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $this->validarDatos($request, $tipoDocumentoProducto->Id_Tipo_Documento_Producto);

        $tipoDocumentoProducto->update([
            'Nombre_Documento' => $datos['nombre_documento'],
            'Carpeta_Slug' => $datos['carpeta_slug'],
            'Codigo_Archivo' => $datos['codigo_archivo'] ?? null,
            'Obligatorio' => $datos['obligatorio'],
        ]);

        return response()->json($tipoDocumentoProducto);
    }

    public function destroy(Request $request, TipoDocumentoProducto $tipoDocumentoProducto): JsonResponse
    {
        $this->verificarSistemas($request);

        $tipoDocumentoProducto->update(['Activo' => false]);

        return response()->json(['message' => 'Tipo de documento de producto inactivado correctamente.']);
    }

    public function activar(Request $request, TipoDocumentoProducto $tipoDocumentoProducto): JsonResponse
    {
        $this->verificarSistemas($request);

        $tipoDocumentoProducto->update(['Activo' => true]);

        return response()->json($tipoDocumentoProducto);
    }

    protected function validarDatos(Request $request, ?int $idActual = null): array
    {
        return $request->validate([
            'nombre_documento' => [
                'required', 'string', 'max:150',
                Rule::unique('Tipo_Documento_Producto', 'Nombre_Documento')->ignore($idActual, 'Id_Tipo_Documento_Producto'),
            ],
            'carpeta_slug' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('Tipo_Documento_Producto', 'Carpeta_Slug')->ignore($idActual, 'Id_Tipo_Documento_Producto'),
            ],
            'codigo_archivo' => ['nullable', 'string', 'max:15'],
            'obligatorio' => ['required', 'boolean'],
        ]);
    }

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar este catálogo.');
        }
    }
}