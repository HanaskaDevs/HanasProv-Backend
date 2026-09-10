<?php

namespace App\Modules\Catalogos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\GrupoProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CRUD del catálogo de grupos de producto (EK, CD, PH, IM...), en la misma
 * línea que CategoriaProductoController: solo Sistemas, verificado acá.
 *
 * Nunca se borra una fila: destroy() la inactiva. Si se borrara, se irían
 * con ella las etiquetas de todos los productos que ya usaban ese grupo
 * (la FK de Producto_Grupo lo impediría de todas formas), y se perdería el
 * dato histórico de por qué un producto estaba en ese grupo.
 */
class GrupoProductoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(
            GrupoProducto::orderBy('Orden')
                ->orderBy('Codigo')
                ->get(['Id_Grupo_Producto', 'Codigo', 'Nombre', 'Descripcion', 'Orden', 'Activo'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $this->validar($request);

        $grupo = GrupoProducto::create([
            'Codigo' => $datos['codigo'],
            'Nombre' => $datos['nombre'],
            'Descripcion' => $datos['descripcion'] ?? null,
            'Orden' => $datos['orden'] ?? 0,
            'Activo' => true,
        ]);

        return response()->json($grupo, 201);
    }

    public function update(Request $request, GrupoProducto $grupo): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $this->validar($request, $grupo);

        $grupo->update([
            'Codigo' => $datos['codigo'],
            'Nombre' => $datos['nombre'],
            'Descripcion' => $datos['descripcion'] ?? null,
            'Orden' => $datos['orden'] ?? $grupo->Orden,
        ]);

        return response()->json($grupo);
    }

    public function destroy(Request $request, GrupoProducto $grupo): JsonResponse
    {
        $this->verificarSistemas($request);

        $grupo->update(['Activo' => false]);

        return response()->json([
            'message' => 'Grupo de producto inactivado. Los productos que ya lo tenían lo conservan.',
        ]);
    }

    public function activar(Request $request, GrupoProducto $grupo): JsonResponse
    {
        $this->verificarSistemas($request);

        $grupo->update(['Activo' => true]);

        return response()->json($grupo);
    }

    /**
     * El código se guarda SIEMPRE en mayúsculas: es una sigla corta que la
     * gente escribe a mano en dos lugares distintos (acá y, el día de
     * mañana, en una importación), y "ek" y "EK" tienen que ser el mismo
     * grupo, no dos.
     */
    protected function validar(Request $request, ?GrupoProducto $grupo = null): array
    {
        if ($request->has('codigo')) {
            $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')), 'UTF-8')]);
        }

        $unico = Rule::unique('Grupo_Producto', 'Codigo');

        if ($grupo) {
            $unico = $unico->ignore($grupo->Id_Grupo_Producto, 'Id_Grupo_Producto');
        }

        return $request->validate([
            'codigo' => ['required', 'string', 'max:10', $unico],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'codigo.unique' => 'Ya existe un grupo de producto con ese código.',
        ]);
    }

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar este catálogo.');
        }
    }
}
