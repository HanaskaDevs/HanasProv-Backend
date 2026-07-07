<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\CrearUsuarioInternoRequest;
use App\Modules\Auth\Http\Requests\CrearUsuarioProveedorRequest;
use App\Modules\Auth\Http\Resources\UsuarioInternoResource;
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

    /**
     * Panel de usuarios internos: lista los usuarios de la empresa activa.
     */
    public function index(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $usuarios = $this->usuarioService->listarInternos($idEmpresa);

        return response()->json(UsuarioInternoResource::collection($usuarios));
    }

    public function show(Usuario $usuario): JsonResponse
    {
        return response()->json(
            new UsuarioInternoResource($usuario->load('usuarioEmpresas.rol'))
        );
    }

    /**
     * Crea un usuario interno con solo email + rol. La empresa se toma de
     * la sesión activa de quien crea (no se pide en el formulario).
     */
    public function storeInterno(CrearUsuarioInternoRequest $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $usuario = $this->usuarioService->crearUsuarioInterno(
            data: $request->validated(),
            creador: $request->user(),
            idEmpresaActiva: $idEmpresaActiva,
        );

        return response()->json(new UsuarioInternoResource($usuario->load('usuarioEmpresas.rol')), 201);
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
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->usuarioService->inactivar($usuario, $request->user(), $idEmpresa);

        return response()->json(['message' => 'Usuario inactivado correctamente.']);
    }
}
