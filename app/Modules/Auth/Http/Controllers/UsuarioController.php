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
use App\Modules\Auth\Http\Resources\UsuarioDetalleResource;

class UsuarioController extends Controller
{
    public function __construct(protected UsuarioService $usuarioService) {}

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
            new UsuarioDetalleResource($usuario->load('usuarioEmpresas.rol', 'usuarioEmpresas.empresa'))
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
            new UsuarioDetalleResource($usuario->load('usuarioEmpresas.rol', 'usuarioEmpresas.empresa'))
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
    public function reactivar(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->usuarioService->reactivar($usuario, $request->user(), $idEmpresa);

        return response()->json(['message' => 'Usuario reactivado correctamente.']);
    }

    public function agregarEmpresa(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->validate(['id_empresa' => ['required', 'integer']])['id_empresa'];
        $idRol = $request->input('id_rol') ? (int) $request->input('id_rol') : null;

        $this->usuarioService->otorgarAccesoEmpresa($usuario, $idEmpresa, $request->user(), $idRol);

        return response()->json(['message' => 'Acceso otorgado correctamente.']);
    }

    public function actualizarEmail(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');
        $nuevoEmail = $request->validate(['email' => ['required', 'email', 'max:150']])['email'];

        $this->usuarioService->actualizarEmail($usuario, $nuevoEmail, $request->user(), $idEmpresa);

        return response()->json(['message' => 'Correo actualizado correctamente.']);
    }

    public function actualizarRolEnEmpresa(Request $request, Usuario $usuario, int $empresa): JsonResponse
    {
        $idRol = (int) $request->validate(['id_rol' => ['required', 'integer']])['id_rol'];

        $this->usuarioService->actualizarRolEnEmpresa($usuario, $empresa, $idRol, $request->user());

        return response()->json(['message' => 'Rol actualizado correctamente.']);
    }

    public function actualizarBodegasEnEmpresa(Request $request, Usuario $usuario, int $empresa): JsonResponse
    {
        $codigosBodega = $request->validate([
            'codigos_bodega' => ['present', 'array'],
            'codigos_bodega.*' => ['string'],
        ])['codigos_bodega'];

        $this->usuarioService->actualizarBodegasAsignadas($usuario, $empresa, $codigosBodega, $request->user());

        return response()->json(['message' => 'Bodegas asignadas actualizadas correctamente.']);
    }

    public function quitarAccesoEmpresa(Request $request, Usuario $usuario, int $empresa): JsonResponse
    {
        $this->usuarioService->quitarAccesoEmpresa($usuario, $empresa, $request->user());

        return response()->json(['message' => 'Acceso removido correctamente.']);
    }
}
