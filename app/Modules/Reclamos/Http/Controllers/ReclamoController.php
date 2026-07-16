<?php

namespace App\Modules\Reclamos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reclamos\Http\Requests\CrearReclamoRequest;
use App\Modules\Reclamos\Http\Requests\ResponderReclamoRequest;
use App\Modules\Reclamos\Http\Resources\ReclamoMensajeResource;
use App\Modules\Reclamos\Http\Resources\ReclamoResource;
use App\Modules\Reclamos\Services\ReclamoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReclamoController extends Controller
{
    public function __construct(protected ReclamoService $reclamoService)
    {
    }

    public function abiertos(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $reclamos = $this->reclamoService->listarInterno($request->user(), $idEmpresaActiva, 'Abierto');

        return response()->json(ReclamoResource::collection($reclamos));
    }

    public function cerrados(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $reclamos = $this->reclamoService->listarInterno($request->user(), $idEmpresaActiva, 'Cerrado');

        return response()->json(ReclamoResource::collection($reclamos));
    }

    public function show(Request $request, int $reclamo): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $detalle = $this->reclamoService->detalle($request->user(), $idEmpresaActiva, $reclamo);

        return response()->json(new ReclamoResource($detalle));
    }

    public function buscarProveedores(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $termino = (string) $request->query('q', '');

        if (strlen($termino) < 2) {
            return response()->json([]);
        }

        $proveedores = $this->reclamoService->buscarProveedores($idEmpresaActiva, $termino);

        return response()->json($proveedores->map(fn ($p) => [
            'id_proveedor' => $p->Id_Proveedor,
            'razon_social' => $p->Razon_Social,
            'ruc' => $p->Ruc,
            'contactos' => array_values(array_filter([
                $p->Email ? ['rol_contacto' => 'Proveedor', 'nombre_contacto' => null, 'email' => $p->Email] : null,
                $p->Correo_Venta ? ['rol_contacto' => 'Ventas', 'nombre_contacto' => $p->Contacto_Venta, 'email' => $p->Correo_Venta] : null,
                $p->Correo_Calidad ? ['rol_contacto' => 'Calidad', 'nombre_contacto' => $p->Contacto_Calidad, 'email' => $p->Correo_Calidad] : null,
                $p->Correo_Contabilidad ? ['rol_contacto' => 'Contabilidad', 'nombre_contacto' => $p->Contacto_Contabilidad, 'email' => $p->Correo_Contabilidad] : null,
                $p->Correo_Representante ? ['rol_contacto' => 'Representante Legal', 'nombre_contacto' => $p->Representante_Legal, 'email' => $p->Correo_Representante] : null,
            ])),
        ]));
    }

    public function store(CrearReclamoRequest $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $reclamo = $this->reclamoService->crear(
            $request->user(),
            $idEmpresaActiva,
            (int) $request->validated('id_proveedor'),
            $request->validated('asunto'),
            $request->validated('mensaje'),
            $request->validated('destinatarios'),
            $request->file('imagenes', []),
        );

        return response()->json(new ReclamoResource($reclamo), 201);
    }

    public function responder(ResponderReclamoRequest $request, int $reclamo): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $mensaje = $this->reclamoService->responder(
            $request->user(),
            $idEmpresaActiva,
            $reclamo,
            $request->validated('mensaje'),
            $request->file('imagenes', []),
        );

        return response()->json(new ReclamoMensajeResource($mensaje), 201);
    }

    public function cerrar(Request $request, int $reclamo): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $this->reclamoService->cerrar($request->user(), $idEmpresaActiva, $reclamo);

        return response()->json(['message' => 'Reclamo cerrado correctamente.']);
    }

    public function verImagen(Request $request, int $imagen)
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return $this->reclamoService->verImagen($request->user(), $idEmpresaActiva, $imagen);
    }
}