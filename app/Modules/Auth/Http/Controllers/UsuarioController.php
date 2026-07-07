<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\CrearUsuarioInternoRequest;
use App\Modules\Auth\Http\Requests\CrearUsuarioProveedorRequest;
use App\Modules\Auth\Http\Resources\UsuarioResource;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function __construct(protected UsuarioService $usuarioService)
    {
    }

    public function storeInterno(CrearUsuarioInternoRequest $request): JsonResponse
    {
        $usuario = $this->usuarioService->crearUsuarioInterno(
            data: $request->validated(),
            creador: $request->user(),
        );

        return response()->json(new UsuarioResource($usuario), 201);
    }

    public function storeProveedor(CrearUsuarioProveedorRequest $request): JsonResponse
    {
        $usuario = $this->usuarioService->crearUsuarioProveedor(
            data: $request->validated(),
            creador: $request->user(),
        );

        return response()->json(new UsuarioResource($usuario), 201);
    }

    public function inactivar(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = $request->attributes->get('id_empresa_activa');

        $this->usuarioService->inactivar($usuario, $request->user(), (int) $idEmpresa);

        return response()->json(['message' => 'Usuario inactivado correctamente.']);
    }
}
