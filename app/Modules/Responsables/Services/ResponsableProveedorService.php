<?php

namespace App\Modules\Responsables\Services;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Responsables\Models\Responsable;
use App\Modules\Responsables\Models\ResponsableProveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A quién le escribe un proveedor cuando tiene una duda.
 *
 * La asignación se carga por CÓDIGO DE PROVEEDOR DE BC (PROV-00000XX),
 * porque así viene el archivo que mantiene Compras y porque ahí están
 * también los proveedores que todavía no abrieron cuenta en el portal.
 *
 * CÓMO SE LLEGA DEL PROVEEDOR DEL PORTAL A SU CÓDIGO DE BC. Hay dos
 * caminos y se usan en este orden:
 *
 *   1. Proveedor.Nro_Proveedor_BC, si está.
 *   2. Si no está: el RUC contra la tabla espejo BC_Ficha_Proveedor.
 *
 * El segundo camino NO es un respaldo menor, es el principal durante el
 * registro: Nro_Proveedor_BC se completa recién cuando el proveedor queda
 * APROBADO y se postea a BC (ver SincronizacionProveedorBcService). Sin la
 * búsqueda por RUC, un proveedor nuevo no vería a su responsable
 * justamente mientras hace el trámite, que es cuando más preguntas tiene.
 * Casi todos los del archivo ya existen en BC, así que por RUC se los
 * encuentra desde el primer día.
 */
class ResponsableProveedorService
{
    /** Formato de los códigos de proveedor de BC: PROV- y siete dígitos. */
    public const PATRON_CODIGO_BC = '/^PROV-\d{7}$/i';

    // ---------------------------------------------------------------
    // Consulta: lo que ve el proveedor
    // ---------------------------------------------------------------

    /**
     * El responsable de este proveedor, o null si no tiene asignado.
     *
     * @return array{nombre: string, correo: string, telefono: ?string, nro_proveedor_bc: string}|null
     */
    public function paraProveedor(Proveedor $proveedor): ?array
    {
        $nroBc = $this->resolverNroBc($proveedor);

        if ($nroBc === null) {
            return null;
        }

        $asignacion = ResponsableProveedor::query()
            ->where('Id_Empresa', $proveedor->Id_Empresa)
            ->where('Nro_Proveedor_BC', $nroBc)
            ->with('responsable')
            ->first();

        $responsable = $asignacion?->responsable;

        // Un responsable dado de baja se trata como "sin responsable": es
        // preferible no mostrar nada a mandar al proveedor a escribirle a
        // alguien que ya no está.
        if ($responsable === null || ! $responsable->Activo) {
            return null;
        }

        return [
            'nombre' => $responsable->Nombre,
            'correo' => $responsable->Correo,
            'telefono' => $responsable->Telefono,
            'nro_proveedor_bc' => $nroBc,
        ];
    }

    /** El responsable del proveedor de este usuario en la empresa activa. */
    public function paraUsuario(Usuario $usuario, int $idEmpresaActiva): ?array
    {
        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        return $proveedor ? $this->paraProveedor($proveedor) : null;
    }

    /** Ver el comentario de clase: primero el código guardado, después el RUC. */
    public function resolverNroBc(Proveedor $proveedor): ?string
    {
        if (! empty($proveedor->Nro_Proveedor_BC)) {
            return trim($proveedor->Nro_Proveedor_BC);
        }

        if (empty($proveedor->Ruc)) {
            return null;
        }

        $empresaBc = $this->empresaBcDe($proveedor->Id_Empresa);

        if ($empresaBc === null) {
            return null;
        }

        $nroBc = DB::table('BC_Ficha_Proveedor')
            ->where('Empresa', $empresaBc)
            ->where('Nro_Identificacion', trim($proveedor->Ruc))
            ->value('Nro_Proveedor');

        return $nroBc !== null ? trim($nroBc) : null;
    }

    // ---------------------------------------------------------------
    // Administración (Sistemas)
    // ---------------------------------------------------------------

    public function listarResponsables()
    {
        return Responsable::query()
            ->withCount('asignaciones')
            ->orderBy('Nombre')
            ->get();
    }

    public function crearResponsable(Usuario $usuario, array $datos): Responsable
    {
        $this->verificarSistemas($usuario);

        $datos = $this->validarResponsable($datos);
        $this->verificarCorreoLibre($datos['correo']);

        return Responsable::create([
            'Nombre' => $datos['nombre'],
            'Correo' => $datos['correo'],
            'Telefono' => $datos['telefono'],
            'Activo' => $datos['activo'] ?? true,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function actualizarResponsable(Usuario $usuario, int $idResponsable, array $datos): Responsable
    {
        $this->verificarSistemas($usuario);

        $responsable = Responsable::findOrFail($idResponsable);
        $datos = $this->validarResponsable($datos);
        $this->verificarCorreoLibre($datos['correo'], $idResponsable);

        $responsable->update([
            'Nombre' => $datos['nombre'],
            'Correo' => $datos['correo'],
            'Telefono' => $datos['telefono'],
            'Activo' => $datos['activo'] ?? $responsable->Activo,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);

        return $responsable->fresh();
    }

    /**
     * Se borra de verdad, pero solo si no tiene proveedores asignados.
     * Borrarlo con asignaciones dejaría a esos proveedores sin contacto
     * sin que nadie se entere; para sacarlo de circulación sin perder la
     * asignación está el campo Activo.
     */
    public function eliminarResponsable(Usuario $usuario, int $idResponsable): void
    {
        $this->verificarSistemas($usuario);

        $responsable = Responsable::withCount('asignaciones')->findOrFail($idResponsable);

        if ($responsable->asignaciones_count > 0) {
            throw ValidationException::withMessages([
                'responsable' => [
                    "No se puede eliminar: tiene {$responsable->asignaciones_count} proveedor(es) asignado(s). "
                    .'Desactívalo, o importá el archivo con otro responsable para esos proveedores.',
                ],
            ]);
        }

        $responsable->delete();
    }

    /**
     * Las asignaciones cargadas de la empresa activa, para la pantalla de
     * administración. Se trae también el proveedor del portal cuando
     * existe -por código o por RUC-, así Sistemas ve de un vistazo
     * cuántas asignaciones corresponden a alguien que ya entró al portal.
     */
    public function listarAsignaciones(Usuario $usuario, int $idEmpresaActiva): array
    {
        $this->verificarSistemas($usuario);

        $empresaBc = $this->empresaBcDe($idEmpresaActiva);

        $asignaciones = ResponsableProveedor::query()
            ->where('Id_Empresa', $idEmpresaActiva)
            ->with('responsable')
            ->orderBy('Nro_Proveedor_BC')
            ->get();

        // Nombre del proveedor según BC, en una sola consulta.
        $nombresBc = $empresaBc === null
            ? collect()
            : DB::table('BC_Ficha_Proveedor')
                ->where('Empresa', $empresaBc)
                ->whereIn('Nro_Proveedor', $asignaciones->pluck('Nro_Proveedor_BC')->all())
                ->pluck('Nombre', 'Nro_Proveedor');

        return $asignaciones->map(fn (ResponsableProveedor $asignacion) => [
            'id_responsable_proveedor' => $asignacion->Id_Responsable_Proveedor,
            'nro_proveedor_bc' => $asignacion->Nro_Proveedor_BC,
            'nombre_en_bc' => $nombresBc[$asignacion->Nro_Proveedor_BC] ?? null,
            'responsable' => [
                'id_responsable' => $asignacion->responsable?->Id_Responsable,
                'nombre' => $asignacion->responsable?->Nombre,
                'correo' => $asignacion->responsable?->Correo,
                'telefono' => $asignacion->responsable?->Telefono,
                'activo' => (bool) $asignacion->responsable?->Activo,
            ],
        ])->all();
    }

    // ---------------------------------------------------------------
    // Importación del archivo
    // ---------------------------------------------------------------

    /**
     * Carga masiva de asignaciones. Igual que la importación de códigos
     * BC del catálogo, va en DOS PASOS: primero se llama con
     * $soloValidar = true y se le muestra el reporte al usuario, y recién
     * si acepta se vuelve a llamar para aplicar. Un archivo de cientos de
     * filas con la mitad mal no se puede deshacer a mano.
     *
     * Reglas:
     *  1. El código tiene que tener forma de código de BC (PROV-0000056).
     *  2. El código tiene que existir en BC_Ficha_Proveedor para la
     *     empresa activa. Un código que BC no conoce no se lo va a poder
     *     atar a nadie nunca, así que entra como error y no en silencio.
     *  3. El correo del responsable tiene que estar en la tabla
     *     Responsable. No se crean personas al vuelo: un correo mal
     *     tipeado crearía un responsable fantasma y el proveedor
     *     escribiría a una casilla que no existe.
     *  4. Un código repetido dentro del archivo es error: no hay forma de
     *     saber cuál de los dos responsables vale.
     *  5. Lo que ya estaba asignado y no viene en el archivo NO se toca.
     *     El archivo agrega y corrige; para quitar una asignación está el
     *     botón de la pantalla.
     *
     * @param  array<int, array{fila:int, codigo_bc:?string, correo:?string}>  $filas
     */
    public function importar(Usuario $usuario, int $idEmpresaActiva, array $filas, bool $soloValidar = true): array
    {
        $this->verificarSistemas($usuario);

        $empresaBc = $this->empresaBcDe($idEmpresaActiva);

        if ($empresaBc === null) {
            throw new \RuntimeException('Esta empresa no tiene configurado el código Empresa_BC.');
        }

        // --- Contexto en dos consultas, no una por fila ---
        $codigos = collect($filas)
            ->pluck('codigo_bc')
            ->map(fn ($codigo) => mb_strtoupper(trim((string) $codigo)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $codigosEnBc = empty($codigos)
            ? collect()
            : DB::table('BC_Ficha_Proveedor')
                ->where('Empresa', $empresaBc)
                ->whereIn('Nro_Proveedor', $codigos)
                ->pluck('Nro_Proveedor')
                ->map(fn ($codigo) => mb_strtoupper(trim($codigo)))
                ->flip();

        $responsablesPorCorreo = Responsable::query()
            ->get()
            ->keyBy(fn (Responsable $r) => mb_strtolower(trim($r->Correo)));

        $asignacionesActuales = ResponsableProveedor::query()
            ->where('Id_Empresa', $idEmpresaActiva)
            ->pluck('Id_Responsable', 'Nro_Proveedor_BC');

        // --- Clasificación fila por fila ---
        $errores = [];
        $nuevas = [];
        $cambios = [];
        $sinCambio = 0;
        $vistos = [];

        foreach ($filas as $fila) {
            $nroFila = (int) ($fila['fila'] ?? 0);
            $codigo = mb_strtoupper(trim((string) ($fila['codigo_bc'] ?? '')));
            $correo = mb_strtolower(trim((string) ($fila['correo'] ?? '')));

            if ($codigo === '' || $correo === '') {
                $errores[] = $this->error($nroFila, $codigo, $correo, 'Falta el código del proveedor o el correo del responsable.');

                continue;
            }

            if (preg_match(self::PATRON_CODIGO_BC, $codigo) !== 1) {
                $errores[] = $this->error($nroFila, $codigo, $correo, 'El código no tiene el formato de BC (ej. PROV-0000056).');

                continue;
            }

            if (isset($vistos[$codigo])) {
                $errores[] = $this->error($nroFila, $codigo, $correo, 'El código está repetido en el archivo.');

                continue;
            }
            $vistos[$codigo] = true;

            if (! $codigosEnBc->has($codigo)) {
                $errores[] = $this->error($nroFila, $codigo, $correo, 'Ese código no existe en BC para esta empresa.');

                continue;
            }

            $responsable = $responsablesPorCorreo->get($correo);

            if ($responsable === null) {
                $errores[] = $this->error(
                    $nroFila,
                    $codigo,
                    $correo,
                    'Ese correo no está en la lista de responsables. Agregalo primero y volvé a importar.'
                );

                continue;
            }

            $idActual = $asignacionesActuales[$codigo] ?? null;

            if ($idActual === null) {
                $nuevas[$codigo] = $responsable->Id_Responsable;
            } elseif ((int) $idActual !== (int) $responsable->Id_Responsable) {
                $cambios[$codigo] = $responsable->Id_Responsable;
            } else {
                $sinCambio++;
            }
        }

        $reporte = [
            'total_filas' => count($filas),
            'nuevas' => count($nuevas),
            'cambios' => count($cambios),
            'sin_cambio' => $sinCambio,
            'errores' => $errores,
            'aplicado' => false,
        ];

        if ($soloValidar) {
            return $reporte;
        }

        $this->aplicar($usuario, $idEmpresaActiva, $nuevas, $cambios);

        $reporte['aplicado'] = true;

        return $reporte;
    }

    /**
     * @param  array<string, int>  $nuevas
     * @param  array<string, int>  $cambios
     */
    protected function aplicar(Usuario $usuario, int $idEmpresaActiva, array $nuevas, array $cambios): void
    {
        $ahora = now();

        DB::transaction(function () use ($usuario, $idEmpresaActiva, $nuevas, $cambios, $ahora) {
            foreach ($nuevas as $codigo => $idResponsable) {
                ResponsableProveedor::create([
                    'Id_Empresa' => $idEmpresaActiva,
                    'Nro_Proveedor_BC' => $codigo,
                    'Id_Responsable' => $idResponsable,
                    'Creado_Por' => $usuario->Id_Usuario,
                    'Fecha_Creacion' => $ahora,
                ]);
            }

            // Se agrupan los cambios por responsable para hacer un UPDATE
            // por persona y no uno por fila: el archivo real son cientos
            // de proveedores repartidos entre cuatro personas.
            $porResponsable = [];
            foreach ($cambios as $codigo => $idResponsable) {
                $porResponsable[$idResponsable][] = $codigo;
            }

            foreach ($porResponsable as $idResponsable => $codigos) {
                ResponsableProveedor::query()
                    ->where('Id_Empresa', $idEmpresaActiva)
                    ->whereIn('Nro_Proveedor_BC', $codigos)
                    ->update([
                        'Id_Responsable' => $idResponsable,
                        'Modificado_Por' => $usuario->Id_Usuario,
                        'Fecha_Modificacion' => $ahora,
                    ]);
            }
        });
    }

    public function eliminarAsignacion(Usuario $usuario, int $idEmpresaActiva, int $idAsignacion): void
    {
        $this->verificarSistemas($usuario);

        ResponsableProveedor::query()
            ->where('Id_Empresa', $idEmpresaActiva)
            ->where('Id_Responsable_Proveedor', $idAsignacion)
            ->firstOrFail()
            ->delete();
    }

    // ---------------------------------------------------------------
    // Interno
    // ---------------------------------------------------------------

    protected function empresaBcDe(int $idEmpresa): ?string
    {
        $empresaBc = Empresa::where('Id_Empresa', $idEmpresa)->value('Empresa_BC');

        return $empresaBc ? trim($empresaBc) : null;
    }

    /** @return array{nombre: string, correo: string, telefono: ?string, activo?: bool} */
    protected function validarResponsable(array $datos): array
    {
        return validator($datos, [
            'nombre' => ['required', 'string', 'max:150'],
            'correo' => ['required', 'email', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'activo' => ['sometimes', 'boolean'],
        ])->validate();
    }

    protected function verificarCorreoLibre(string $correo, ?int $idExcluido = null): void
    {
        $existe = Responsable::query()
            ->where('Correo', $correo)
            ->when($idExcluido !== null, fn ($query) => $query->where('Id_Responsable', '!=', $idExcluido))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'correo' => ['Ya hay un responsable con ese correo.'],
            ]);
        }
    }

    protected function error(int $fila, string $codigo, string $correo, string $motivo): array
    {
        return ['fila' => $fila, 'codigo_bc' => $codigo, 'correo' => $correo, 'motivo' => $motivo];
    }

    protected function verificarSistemas(Usuario $usuario): void
    {
        if (! $usuario->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar los responsables.');
        }
    }
}
