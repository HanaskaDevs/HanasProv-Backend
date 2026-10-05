<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\CrearUsuarioInternoRequest;
use App\Modules\Auth\Http\Requests\CrearUsuarioProveedorRequest;
use App\Modules\Auth\Http\Requests\CrearUsuariosProveedorLoteRequest;
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

        $resultado = $this->usuarioService->crearUsuarioInterno(
            data: $request->validated(),
            creador: $request->user(),
        );

        // El alta y el envío del código son dos cosas distintas: el usuario
        // puede quedar creado y el correo no salir. Se devuelven por
        // separado para que la pantalla pueda decirlo tal cual.
        return response()->json([
            'usuario' => new UsuarioInternoResource($resultado['usuario']->load('usuarioEmpresas.rol')),
            'envio' => $resultado['envio']->toArray(),
        ], 201);
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

        $resultado = $this->usuarioService->crearUsuarioProveedor(
            data: $request->validated(),
            creador: $request->user(),
        );

        return response()->json([
            'usuario' => new UsuarioExternoResource($resultado['usuario']->load('usuarioEmpresas.rol')),
            'envio' => $resultado['envio']->toArray(),
        ], 201);
    }

    /**
     * Carga masiva de usuarios externos (Proveedores) desde un Excel.
     * Solo rol Sistemas (lo valida el Service, no basta con estar en este
     * grupo de rutas).
     *
     * Devuelve 200 y NO 201/207 a propósito: la respuesta no es "se creó un
     * recurso" sino un REPORTE fila por fila, donde conviven creados,
     * omitidos y errores. Quien llama siempre tiene que leer el cuerpo, así
     * que un código de éxito parcial solo agregaría ruido.
     */
    public function storeProveedoresLote(CrearUsuariosProveedorLoteRequest $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $reporte = $this->usuarioService->crearUsuariosProveedorEnLote(
            filas: $request->validated()['filas'],
            creador: $request->user(),
            idEmpresaActiva: $idEmpresaActiva,
        );

        return response()->json($reporte);
    }

    /**
     * Enlace de activación para mandarle al proveedor por otro canal. Las
     * reglas (quién puede, a quién) las valida el Service.
     */
    public function enlaceActivacion(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->usuarioService->generarEnlaceActivacion($usuario, $request->user(), $idEmpresaActiva)
        );
    }

    /**
     * Reenvío masivo del código de activación a los proveedores
     * seleccionados. Quién califica y quién puede hacerlo lo decide el
     * Service (ver UsuarioService::reenviarActivacionMasivo).
     *
     * El tope de 200 ids no es de negocio: los ids viajan como parámetros
     * de un whereIn, y SQL Server admite 2100 por consulta.
     */
    public function reenviarActivacionMasivo(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ])['ids'];

        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->usuarioService->reenviarActivacionMasivo($ids, $request->user(), $idEmpresaActiva)
        );
    }

    /**
     * Estado de la cola de correo, para avisar antes de una carga masiva.
     * Solo Sistemas (lo valida el Service).
     */
    public function estadoColaCorreo(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->usuarioService->estadoColaCorreo($request->user(), $idEmpresaActiva)
        );
    }

    public function reenviarCodigo(Request $request, Usuario $usuario): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->usuarioService->reenviarCodigoActivacion($usuario, $request->user(), $idEmpresa)->toArray()
        );
    }

    /**
     * Borrado DEFINITIVO de una cuenta que nunca se activó. Las reglas
     * (solo Sistemas, solo sin activar, sin información asociada) las
     * valida el Service: acá no se repiten para que no puedan quedar
     * desalineadas.
     */
    public function eliminarDefinitivamente(Request $request, Usuario $usuario): JsonResponse
    {
        $this->usuarioService->eliminarDefinitivamente($usuario, $request->user());

        return response()->json(['message' => 'Cuenta eliminada definitivamente.']);
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

        return response()->json(
            $this->usuarioService->reactivar($usuario, $request->user(), $idEmpresa)->toArray()
        );
    }

    public function reenviarActivacion(Request $request, Usuario $usuario): JsonResponse
    {
        return response()->json(
            $this->usuarioService->reenviarActivacion($usuario, $request->user())->toArray()
        );
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