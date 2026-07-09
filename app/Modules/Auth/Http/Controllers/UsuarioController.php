<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\CrearUsuarioInternoRequest;
use App\Modules\Auth\Http\Requests\CrearUsuarioProveedorRequest;
use App\Modules\Auth\Http\Resources\UsuarioExternoResource;
use App\Modules\Auth\Http\Resources\UsuarioInternoResource;
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
     * Panel de usuarios internos: solo visible para rol Sistemas.
     */
    public function indexInternos(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $usuarios = $this->usuarioService->listarInternos($idEmpresa, $request->user());

        return response()->json(UsuarioInternoResource::collection($usuarios));
    }

    public function showInterno(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->usuarioService->verificarAccesoPanelInternos($request->user(), $idEmpresa);

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

    /**
     * Panel de usuarios externos (Proveedores): visible para Sistemas o Admin.
     */
    public function indexExternos(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $usuarios = $this->usuarioService->listarExternos($idEmpresa, $request->user());

        return response()->json(UsuarioExternoResource::collection($usuarios));
    }

    public function showExterno(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->usuarioService->verificarAccesoPanelExternos($request->user(), $idEmpresa);

        return response()->json(
            new UsuarioExternoResource($usuario->load(['usuarioEmpresas.rol', 'proveedor']))
        );
    }

    /**
     * Crea un usuario externo (Proveedor) con solo email. Permitido para
     * rol Sistemas o Admin dentro de la empresa activa. El Proveedor en sí
     * se crea después, cuando el usuario completa la Ficha tras activarse.
     */
    public function storeProveedor(CrearUsuarioProveedorRequest $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $usuario = $this->usuarioService->crearUsuarioProveedor(
            data: $request->validated(),
            creador: $request->user(),
            idEmpresaActiva: $idEmpresaActiva,
        );

        return response()->json(new UsuarioExternoResource($usuario->load('usuarioEmpresas.rol')), 201);
    }

    public function reenviarCodigo(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->usuarioService->reenviarCodigoActivacion($usuario, $request->user(), $idEmpresa);

        return response()->json(['message' => 'Código de activación reenviado correctamente.']);
    }

    public function inactivar(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->usuarioService->inactivar($usuario, $request->user(), $idEmpresa);

        return response()->json(['message' => 'Usuario inactivado correctamente.']);
    }
}