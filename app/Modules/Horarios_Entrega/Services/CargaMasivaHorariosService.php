<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Carga masiva del calendario de horarios desde Excel (21-oct-2026). Solo
 * Sistemas.
 *
 * EL ARCHIVO REEMPLAZA EL CALENDARIO de las clasificaciones que trae
 * (decisión del usuario): para cada clasificación presente en el archivo,
 *   - el horario que ya existía (mismo proveedor, día y hora de llegada) se
 *     ACTUALIZA: un proveedor puede tener varias entregas el mismo día;
 *   - el que no existía se CREA;
 *   - el que estaba cargado y no viene en el archivo se DA DE BAJA
 *     (Activo = 0, como el eliminar de la pantalla: nunca se borra físico).
 * Las clasificaciones que el archivo no trae no se tocan.
 *
 * SE CARGAN LAS FILAS VÁLIDAS y las que tienen error se informan. Como
 * reemplaza, una fila con error también se lleva el horario que ese
 * proveedor tenía ese día: por eso todo pasa primero por una VISTA PREVIA
 * ($confirmar = false) que no escribe nada y dice cuántos se crean, se
 * actualizan y se dan de baja, con nombre y apellido. Recién con la
 * confirmación se aplica, en una sola transacción.
 *
 * El proveedor se identifica por código BC o por RUC, el mismo para todo
 * el archivo. Lo decide la columna que trae (ver $identificador).
 */
class CargaMasivaHorariosService
{
    public const MAX_FILAS = 1000;

    public const IDENTIFICADORES = ['codigo_bc', 'ruc'];

    /** Lo que se escribe en el Excel -> el valor que se guarda. */
    private const CLASIFICACIONES = [
        'PERECIBLES' => HorarioEntregaProveedor::PERECIBLES,
        'NOPERECIBLES' => HorarioEntregaProveedor::NO_PERECIBLES,
        'FRUVER' => HorarioEntregaProveedor::FRUVER,
    ];

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    public function procesar(Usuario $usuario, int $idEmpresa, string $identificador, array $filas, bool $confirmar): array
    {
        if (! $usuario->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo Sistemas puede hacer la carga masiva de horarios.');
        }

        if (! in_array($identificador, self::IDENTIFICADORES, true)) {
            throw ValidationException::withMessages(['identificador' => ['El archivo debe identificar a los proveedores por código BC o por RUC.']]);
        }

        if ($filas === []) {
            throw ValidationException::withMessages(['filas' => ['El archivo no trae ningún horario.']]);
        }

        if (count($filas) > self::MAX_FILAS) {
            throw ValidationException::withMessages(['filas' => ['Se pueden cargar hasta '.self::MAX_FILAS.' horarios por archivo.']]);
        }

        [$proveedores, $soloEnBc] = $this->proveedoresPorIdentificador($idEmpresa, $identificador, $filas);

        // 1) Cada fila por separado: formato y proveedor.
        $resultados = [];
        $vistos = [];

        foreach (array_values($filas) as $indice => $fila) {
            $resultados[] = $this->interpretarFila($fila, $indice, $identificador, $proveedores, $soloEnBc, $vistos);
        }

        // 2) Qué clasificaciones se reemplazan: las que el archivo trae,
        // aunque sea en una fila con otro error.
        $clasificaciones = collect($resultados)->pluck('clasificacion')->filter()->unique()->values();

        $existentes = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereIn('Clasificacion', $clasificaciones)
            ->with('proveedor')
            ->get();

        $porClave = $existentes->groupBy(fn (HorarioEntregaProveedor $h) => self::clave($h->Id_Proveedor, $h->Clasificacion, $h->Dia_Entrega, $h->Hora_Llegada));
        $actualizados = [];

        foreach ($resultados as &$resultado) {
            if ($resultado['estado'] === 'error') {
                continue;
            }

            $existente = $porClave->get(self::clave($resultado['id_proveedor'], $resultado['clasificacion'], $resultado['dia_entrega'], $resultado['hora_llegada']))?->first();

            $resultado['estado'] = $existente ? 'actualizar' : 'crear';
            $resultado['id_horario_existente'] = $existente?->Id_Horario_Entrega_Proveedor;

            if ($existente) {
                $actualizados[$existente->Id_Horario_Entrega_Proveedor] = true;
            }
        }
        unset($resultado);

        $bajas = $existentes->reject(fn (HorarioEntregaProveedor $h) => isset($actualizados[$h->Id_Horario_Entrega_Proveedor]))->values();

        if ($confirmar) {
            $this->aplicar($usuario, $idEmpresa, $resultados, $bajas);
        }

        return [
            'aplicado' => $confirmar,
            'identificador' => $identificador,
            'clasificaciones' => $clasificaciones->all(),
            'resumen' => [
                'total' => count($resultados),
                'crear' => collect($resultados)->where('estado', 'crear')->count(),
                'actualizar' => collect($resultados)->where('estado', 'actualizar')->count(),
                'errores' => collect($resultados)->where('estado', 'error')->count(),
                'dar_de_baja' => $bajas->count(),
            ],
            'filas' => collect($resultados)->map(fn ($r) => collect($r)->except(['id_horario_existente', 'datos'])->all())->all(),
            'bajas' => $bajas->map(fn (HorarioEntregaProveedor $h) => [
                'nombre_proveedor' => $h->proveedor?->nombreVisible(),
                'clasificacion' => $h->Clasificacion,
                'dia_entrega' => $h->Dia_Entrega,
                'hora_llegada' => $h->Hora_Llegada,
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultados
     * @param  Collection<int, HorarioEntregaProveedor>  $bajas
     */
    private function aplicar(Usuario $usuario, int $idEmpresa, array $resultados, Collection $bajas): void
    {
        DB::transaction(function () use ($usuario, $idEmpresa, $resultados, $bajas) {
            $ahora = now();

            foreach ($resultados as $r) {
                if ($r['estado'] === 'crear') {
                    HorarioEntregaProveedor::create([
                        ...$r['datos'],
                        'Id_Empresa' => $idEmpresa,
                        'Activo' => true,
                        'Creado_Por' => $usuario->Id_Usuario,
                        'Fecha_Creacion' => $ahora,
                    ]);
                } elseif ($r['estado'] === 'actualizar') {
                    HorarioEntregaProveedor::whereKey($r['id_horario_existente'])->update([
                        ...$r['datos'],
                        'Modificado_Por' => $usuario->Id_Usuario,
                        'Fecha_Modificacion' => $ahora,
                    ]);
                }
            }

            if ($bajas->isNotEmpty()) {
                HorarioEntregaProveedor::whereIn('Id_Horario_Entrega_Proveedor', $bajas->pluck('Id_Horario_Entrega_Proveedor'))
                    ->update([
                        'Activo' => false,
                        'Modificado_Por' => $usuario->Id_Usuario,
                        'Fecha_Modificacion' => $ahora,
                    ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, Proveedor>  $proveedores
     * @param  array<string, string>  $soloEnBc  identificador -> nombre en BC, de los que no están en el portal
     * @param  array<string, int>  $vistos  clave proveedor+clasificación+día+hora -> número de fila
     * @return array<string, mixed>
     */
    private function interpretarFila(array $fila, int $indice, string $identificador, array $proveedores, array $soloEnBc, array &$vistos): array
    {
        $numeroFila = (int) ($fila['numero_fila'] ?? $indice + 2);
        $valorIdentificador = self::normalizarIdentificador($fila['identificador'] ?? null, $identificador);
        $clasificacion = self::CLASIFICACIONES[self::sinTildes($fila['clasificacion'] ?? '')] ?? null;
        $dia = self::dia($fila['dia_entrega'] ?? null);

        $base = [
            'numero_fila' => $numeroFila,
            'identificador' => $valorIdentificador,
            'clasificacion' => $clasificacion,
            'dia_entrega' => $dia,
            'id_proveedor' => null,
            'nombre_proveedor' => null,
        ];
        $error = fn (string $mensaje) => [...$base, 'estado' => 'error', 'mensaje' => $mensaje];

        if (! $clasificacion) {
            return $error('Clasificación no reconocida: usa Perecibles, No Perecibles o Fruver.');
        }

        if (! $dia) {
            return $error('Día no reconocido: usa Lunes, Martes, Miércoles, Jueves, Viernes, Sábado o Domingo.');
        }

        if (! $valorIdentificador) {
            return $error($identificador === 'ruc' ? 'Falta el RUC del proveedor.' : 'Falta el código BC del proveedor.');
        }

        $proveedor = $proveedores[$valorIdentificador] ?? null;

        if (! $proveedor && isset($soloEnBc[$valorIdentificador])) {
            // Existe en Business Central pero nunca se registró en el portal:
            // el horario necesita la ficha del proveedor en el portal.
            return $error(($identificador === 'ruc' ? "El RUC {$valorIdentificador}" : "El código BC {$valorIdentificador}")
                ." es de {$soloEnBc[$valorIdentificador]} en Business Central, pero ese proveedor no está registrado en el portal. Créale la cuenta primero.");
        }

        if (! $proveedor) {
            return $error($identificador === 'ruc'
                ? "No hay ningún proveedor con RUC {$valorIdentificador} en esta empresa."
                : "El código BC {$valorIdentificador} no está en los proveedores de esta empresa ni en la copia de Business Central.");
        }

        $base['id_proveedor'] = $proveedor->Id_Proveedor;
        $base['nombre_proveedor'] = $proveedor->nombreVisible();

        $llegada = self::hora($fila['hora_llegada'] ?? null);

        if (! $llegada) {
            return $error('Hora de llegada vacía o mal escrita (usa el formato 08:30).');
        }

        $permanencia = self::minutos($fila['tiempo_permanencia'] ?? null);
        $preparacion = self::minutos($fila['tiempo_preparacion'] ?? null);

        if ($permanencia === false || $preparacion === false) {
            return $error('Tiempo mal escrito: usa minutos (30) o horas y minutos (0:30).');
        }

        // La salida se calcula siempre (23-oct-2026): llegada + permanencia,
        // igual que en la pantalla. Una columna de salida en el archivo se
        // ignora.
        $salida = HorarioEntregaService::horaDeSalida($llegada, $permanencia);

        $anden = trim((string) ($fila['anden_puerta'] ?? ''));

        if (mb_strlen($anden) > 20) {
            return $error('El andén o puerta no puede tener más de 20 caracteres.');
        }

        $clave = self::clave($proveedor->Id_Proveedor, $clasificacion, $dia, $llegada);

        if (isset($vistos[$clave])) {
            return $error("Repetida: el proveedor ya tiene un horario ese día a las {$llegada} en la fila {$vistos[$clave]}.");
        }

        $vistos[$clave] = $numeroFila;

        $datos = [
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => $clasificacion,
            'Dia_Entrega' => $dia,
            'Anden_Puerta' => $anden !== '' ? mb_strtoupper($anden) : null,
            'Hora_Llegada' => $llegada,
            'Tiempo_Permanencia_Min' => $permanencia,
            'Hora_Salida' => $salida,
        ];

        // La preparación ya no se pide (21-oct-2026). Solo se escribe si el
        // archivo la trae, así una actualización no borra la que había.
        if ($preparacion !== null && $clasificacion === HorarioEntregaProveedor::FRUVER) {
            $datos['Tiempo_Preparacion_Min'] = $preparacion;
        }

        return [
            ...$base,
            'estado' => 'valida',
            'mensaje' => null,
            'hora_llegada' => $llegada,
            'hora_salida' => $salida,
            'datos' => $datos,
        ];
    }

    /**
     * Proveedores ACTIVOS de la empresa, indexados por el identificador del
     * archivo. Por código BC se buscan los dos lugares donde vive el código:
     * la copia de BC (BC_Ficha_Proveedor, por RUC) y el Nro_Proveedor_BC que
     * el portal guarda al aprobar (ver HorarioEntregaService::
     * resolverCodigosBc).
     *
     * Devuelve además los que están en la copia de BC pero NO en el portal
     * (identificador -> nombre en BC), para decir "no está registrado en el
     * portal" en vez de "no se encontró", que confundía: el código existe.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{0: array<string, Proveedor>, 1: array<string, string>}
     */
    private function proveedoresPorIdentificador(int $idEmpresa, string $identificador, array $filas): array
    {
        $valores = collect($filas)
            ->map(fn ($f) => self::normalizarIdentificador($f['identificador'] ?? null, $identificador))
            ->filter()
            ->unique()
            ->values();

        if ($valores->isEmpty()) {
            return [[], []];
        }

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresa)->where('Activo', 1)->get();
        $porRuc = $proveedores->keyBy(fn (Proveedor $p) => trim((string) $p->Ruc));
        $empresaBc = trim((string) Empresa::find($idEmpresa)?->Empresa_BC);
        $resultado = [];
        $soloEnBc = [];

        if ($identificador === 'ruc') {
            foreach ($valores as $ruc) {
                if ($porRuc->has($ruc)) {
                    $resultado[$ruc] = $porRuc->get($ruc);
                }
            }

            if ($empresaBc !== '') {
                DB::table('BC_Ficha_Proveedor')
                    ->where('Empresa', $empresaBc)
                    ->whereIn('Nro_Identificacion', $valores->diff(array_keys($resultado))->values())
                    ->get(['Nro_Identificacion', 'Nombre'])
                    ->each(function ($fila) use (&$soloEnBc) {
                        $soloEnBc[trim($fila->Nro_Identificacion)] = trim($fila->Nombre);
                    });
            }

            return [$resultado, $soloEnBc];
        }

        foreach ($proveedores as $p) {
            $codigo = mb_strtoupper(trim((string) $p->Nro_Proveedor_BC));

            if ($codigo !== '' && $valores->contains($codigo)) {
                $resultado[$codigo] = $p;
            }
        }

        if ($empresaBc !== '') {
            DB::table('BC_Ficha_Proveedor')
                ->where('Empresa', $empresaBc)
                ->whereIn('Nro_Proveedor', $valores)
                ->get(['Nro_Proveedor', 'Nro_Identificacion', 'Nombre'])
                ->each(function ($fila) use (&$resultado, &$soloEnBc, $porRuc) {
                    $codigo = mb_strtoupper(trim($fila->Nro_Proveedor));
                    $proveedor = $porRuc->get(trim((string) $fila->Nro_Identificacion));

                    if ($proveedor) {
                        $resultado[$codigo] = $proveedor;
                    } elseif (! isset($resultado[$codigo])) {
                        $soloEnBc[$codigo] = trim($fila->Nombre);
                    }
                });
        }

        return [$resultado, $soloEnBc];
    }

    // ---------- Lectura de valores ----------

    /**
     * Qué identifica a UN horario: proveedor, clasificación, día Y hora de
     * llegada. Un mismo proveedor puede entregar varias veces el mismo día
     * a distintas horas (corrección del usuario, 21-oct-2026): antes la
     * hora no contaba y la segunda entrega del día salía como "repetida".
     */
    private static function clave(int $idProveedor, string $clasificacion, string $dia, ?string $horaLlegada): string
    {
        // substr: la hora se guarda "HH:MM", pero por las dudas de un "HH:MM:SS".
        return "{$idProveedor}|{$clasificacion}|{$dia}|".substr((string) $horaLlegada, 0, 5);
    }

    private static function vacio(mixed $valor): bool
    {
        return $valor === null || trim((string) $valor) === '';
    }

    /** "No Perecibles" / "no_perecibles" / "NO PERECIBLES" -> "NOPERECIBLES". */
    private static function sinTildes(mixed $texto): string
    {
        return preg_replace('/[^A-Z]/', '', Str::upper(Str::ascii(trim((string) $texto))));
    }

    private static function normalizarIdentificador(mixed $valor, string $identificador): ?string
    {
        if (self::vacio($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        // El RUC solo tiene dígitos: se quitan espacios y guiones que a
        // veces trae el Excel. Un RUC que empieza con 0 y Excel guardó
        // como número llega con 12 dígitos: se completa el cero.
        if ($identificador === 'ruc') {
            $digitos = preg_replace('/\D/', '', $texto);

            return strlen($digitos) === 12 ? '0'.$digitos : ($digitos !== '' ? $digitos : null);
        }

        return mb_strtoupper(preg_replace('/\s+/', '', $texto));
    }

    /** "Miércoles", "MIERCOLES", "miercoles" -> "Miercoles" (como se guarda). */
    private static function dia(mixed $valor): ?string
    {
        $buscado = self::sinTildes($valor);

        foreach (HorarioEntregaProveedor::DIAS as $dia) {
            if (self::sinTildes($dia) === $buscado) {
                return $dia;
            }
        }

        return null;
    }

    /** "8:00", "08:00:00", "8:00 am", "2:30 PM" -> "HH:MM". null si no se entiende. */
    private static function hora(mixed $valor): ?string
    {
        if (self::vacio($valor)) {
            return null;
        }

        $texto = Str::lower(trim((string) $valor));

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(a\.?\s*m\.?|p\.?\s*m\.?)?$/', $texto, $m)) {
            return null;
        }

        $horas = (int) $m[1];
        $minutos = (int) $m[2];
        $sufijo = isset($m[3]) ? str_replace(['.', ' '], '', $m[3]) : '';

        if ($sufijo === 'pm' && $horas < 12) {
            $horas += 12;
        } elseif ($sufijo === 'am' && $horas === 12) {
            $horas = 0;
        }

        if ($horas > 23 || $minutos > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $horas, $minutos);
    }

    /**
     * "30" -> 30, "0:30" / "0:30:00" -> 30, "1:30" -> 90. null si viene
     * vacío, false si viene algo que no se entiende.
     */
    private static function minutos(mixed $valor): int|false|null
    {
        if (self::vacio($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        if (preg_match('/^\d{1,4}$/', $texto)) {
            $total = (int) $texto;
        } elseif (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $texto, $m)) {
            $total = ((int) $m[1]) * 60 + (int) $m[2];
        } else {
            return false;
        }

        return $total <= 1000 ? $total : false;
    }
}
