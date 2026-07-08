<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Modules\Auth\Http\Resources\RolResource;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de roles, usado por el selector al crear usuarios internos.
 * Es solo lectura de catálogo, no expone nada sensible -> cualquier
 * usuario autenticado puede consultarlo (la autorización real de "quién
 * puede asignar qué rol" sigue viviendo en UsuarioService::crearUsuarioInterno).
 */
class RolController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Rol::where('Activo', true)->orderBy('Nombre_Rol')->get();

        return response()->json(RolResource::collection($roles));
    }
}
