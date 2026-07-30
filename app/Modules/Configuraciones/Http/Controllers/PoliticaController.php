<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Http\Requests\GuardarPoliticaRequest;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoliticaController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    /** Admin (Sistemas): lista todas, activas e inactivas, para gestionarlas. */
    public function index(): JsonResponse
    {
        return response()->json($this->configuracionService->listarPoliticas());
    }

    public function store(GuardarPoliticaRequest $request): JsonResponse
    {
        $politica = $this->configuracionService->crearPolitica($request->user(), $request->validated());

        return response()->json($politica, 201);
    }

    public function update(GuardarPoliticaRequest $request, int $politica): JsonResponse
    {
        $actualizada = $this->configuracionService->actualizarPolitica($request->user(), $politica, $request->validated());

        return response()->json($actualizada);
    }

    public function destroy(Request $request, int $politica): JsonResponse
    {
        $this->configuracionService->eliminarPolitica($request->user(), $politica);

        return response()->json(['message' => 'Política eliminada correctamente.']);
    }

    /**
     * Sección Políticas dentro de la plataforma: cualquier usuario logueado
     * (proveedor o interno), solo lectura, solo las activas.
     */
    public function verActivas(): JsonResponse
    {
        return response()->json($this->configuracionService->listarPoliticasActivas());
    }

    /**
     * Recibe un PDF y devuelve su texto plano, para que el admin lo pegue
     * en el campo Descripción. El PDF nunca se guarda en el servidor.
     */
    public function extraerTextoPdf(Request $request): JsonResponse
    {
        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'], // 20 MB
        ]);

        $texto = $this->configuracionService->extraerTextoPdf($request->user(), $request->file('pdf'));

        return response()->json(['texto' => $texto]);
    }
}