<?php

namespace App\Modules\Horarios_Entrega\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Services\CargaMasivaHorariosService;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Calendario de horarios de entrega para Business Central (09-nov-2026).
 *
 * BC tiene su propia pantalla del calendario (extensión Calendario_Portal_
 * Proveedores) y la sincroniza hacia acá con la MISMA lógica de la carga
 * masiva por Excel: CargaMasivaHorariosService::procesar, que reemplaza el
 * calendario de las clasificaciones que vienen en el envío. No hay una
 * segunda implementación: lo que vale para el Excel vale para BC.
 *
 * Autenticación: token compartido (TokenIntegracionBc). La empresa viene en
 * la petición como el nombre o código de la compañía de BC (Empresa.
 * Empresa_BC o Codigo_Company_BC); el usuario en cuyo nombre se escribe sale
 * de la configuración, y tiene que ser Sistemas o Admin en esa empresa (lo
 * corta el propio service, igual que para una persona).
 */
class IntegracionBcHorariosController extends Controller
{
    public function __construct(
        protected HorarioEntregaService $servicio,
        protected CargaMasivaHorariosService $cargaMasiva
    ) {
    }

    /**
     * El calendario vigente del portal, para que BC lo traiga antes de
     * empezar a sincronizar (así no pisa con una lista vacía lo que ya se
     * cargó a mano). Igual que GET /horarios-entrega, más el RUC, porque BC
     * ubica al proveedor por código o, si el portal no lo tiene, por RUC.
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'empresa_bc' => ['required', 'string', 'max:100'],
            'clasificacion' => ['nullable', 'string'],
        ]);

        $empresa = $this->empresa($datos['empresa_bc']);
        $horarios = $this->servicio->listar($this->usuario(), $empresa->Id_Empresa, $datos['clasificacion'] ?? null);

        $rucs = Proveedor::whereIn('Id_Proveedor', $horarios->pluck('id_proveedor')->unique())
            ->pluck('Ruc', 'Id_Proveedor');

        return response()->json([
            'id_empresa' => $empresa->Id_Empresa,
            'horarios' => $horarios
                ->map(fn (array $h) => [...$h, 'ruc_proveedor' => trim((string) ($rucs[$h['id_proveedor']] ?? '')) ?: null])
                ->values(),
        ]);
    }

    /**
     * Mismo cuerpo que POST /horarios-entrega/carga-masiva más empresa_bc.
     * Con confirmar = false es vista previa (no escribe); con true aplica.
     *
     * conservar_ids (opcional, 09-nov-2026): ids de horarios del portal que no
     * se dan de baja aunque no vengan en filas. BC manda ahí los horarios de
     * proveedores que no existen en BC (los ve en el GET de arriba), para que
     * sincronizar no se los lleve.
     */
    public function cargaMasiva(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'empresa_bc' => ['required', 'string', 'max:100'],
            'identificador' => ['required', 'string'],
            'confirmar' => ['sometimes', 'boolean'],
            'filas' => ['present', 'array'],
            'filas.*' => ['array'],
            'conservar_ids' => ['sometimes', 'array', 'max:1000'],
            'conservar_ids.*' => ['integer'],
        ]);

        $empresa = $this->empresa($datos['empresa_bc']);

        $resultado = $this->cargaMasiva->procesar(
            $this->usuario(),
            $empresa->Id_Empresa,
            $datos['identificador'],
            $datos['filas'],
            $request->boolean('confirmar'),
            array_map('intval', $datos['conservar_ids'] ?? [])
        );

        return response()->json(['id_empresa' => $empresa->Id_Empresa, ...$resultado]);
    }

    /**
     * Empresa del portal a partir de la compañía de BC. Se acepta tanto
     * Empresa_BC (nombre con el que el espejo RAW_BC la conoce; en la base
     * viene con espacios al final por ser CHAR) como Codigo_Company_BC
     * (el código corto, "CF"), sin distinguir mayúsculas.
     */
    private function empresa(string $empresaBc): Empresa
    {
        $buscado = mb_strtolower(trim($empresaBc));

        $empresa = Empresa::where('Activo', 1)
            ->where(function ($q) use ($buscado) {
                $q->whereRaw('LOWER(LTRIM(RTRIM(Empresa_BC))) = ?', [$buscado])
                    ->orWhereRaw('LOWER(LTRIM(RTRIM(Codigo_Company_BC))) = ?', [$buscado]);
            })
            ->first();

        if (! $empresa) {
            throw ValidationException::withMessages([
                'empresa_bc' => ["No hay ninguna empresa activa del portal asociada a la compañía de Business Central \"{$empresaBc}\"."],
            ]);
        }

        return $empresa;
    }

    /** Usuario interno en cuyo nombre escribe la integración (configuración). */
    private function usuario(): Usuario
    {
        $correo = trim((string) config('integraciones.bc.usuario'));

        $usuario = $correo !== ''
            ? Usuario::where('Email', $correo)->where('Activo', 1)->first()
            : null;

        if (! $usuario) {
            throw new HttpException(503, 'La integración con Business Central no tiene un usuario interno válido configurado en el portal (INTEGRACION_BC_USUARIO).');
        }

        return $usuario;
    }
}
