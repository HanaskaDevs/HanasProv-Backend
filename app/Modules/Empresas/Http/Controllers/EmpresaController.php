<?php

namespace App\Modules\Empresas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Modules\Empresas\Http\Requests\GuardarEmpresaRequest;
use App\Modules\Empresas\Http\Resources\EmpresaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EmpresaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(EmpresaResource::collection(Empresa::orderBy('Razon_Social')->get()));
    }

    public function store(GuardarEmpresaRequest $request): JsonResponse
{
    $this->verificarSistemas($request);
    $datos = $request->validated();

    $empresa = Empresa::create([
        'Razon_Social' => $datos['razon_social'],
        'Ruc' => $datos['ruc'],
        'Nombre_Comercial' => $datos['nombre_comercial'] ?? null,
        'Logo_Url' => $datos['logo_url'] ?? null,
        'Empresa_BC' => $datos['empresa_bc'] ?? null,
        'Activo' => true,
        'Creado_Por' => $request->user()->Id_Usuario,
        'Fecha_Creacion' => now(),
    ]);

    $idRolSistemas = \App\Models\Rol::where('Nombre_Rol', 'Sistemas')->value('Id_Rol');

    if ($idRolSistemas) {
        \App\Modules\Auth\Models\UsuarioEmpresa::create([
            'Id_Usuario' => $request->user()->Id_Usuario,
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Rol' => $idRolSistemas,
            'Activo' => true,
            'Creado_Por' => $request->user()->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    return response()->json(new EmpresaResource($empresa), 201);
}

    public function update(GuardarEmpresaRequest $request, Empresa $empresa): JsonResponse
    {
        $this->verificarSistemas($request);
        $datos = $request->validated();

        $empresa->update([
            'Razon_Social' => $datos['razon_social'],
            'Ruc' => $datos['ruc'],
            'Nombre_Comercial' => $datos['nombre_comercial'] ?? null,
            'Logo_Url' => $datos['logo_url'] ?? null,
            'Empresa_BC' => $datos['empresa_bc'] ?? null,
            'Modificado_Por' => $request->user()->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);

        return response()->json(new EmpresaResource($empresa));
    }

    public function destroy(Request $request, Empresa $empresa): JsonResponse
{
    $this->verificarSistemas($request);

    $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

    if ($empresa->Id_Empresa === $idEmpresaActiva) {
        throw new AccessDeniedHttpException('No puede inactivar la empresa en la que tiene su sesión activa. Cambie de empresa primero.');
    }

    $empresa->update([
        'Activo' => false,
        'Modificado_Por' => $request->user()->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ]);

    return response()->json(['message' => 'Empresa inactivada correctamente.']);
}

public function activar(Request $request, Empresa $empresa): JsonResponse
{
    $this->verificarSistemas($request);

    $empresa->update([
        'Activo' => true,
        'Modificado_Por' => $request->user()->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ]);

    return response()->json(new EmpresaResource($empresa));
}

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar empresas.');
        }
    }
}