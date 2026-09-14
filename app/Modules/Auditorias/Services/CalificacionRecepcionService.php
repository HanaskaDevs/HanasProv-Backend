<?php

namespace App\Modules\Auditorias\Services;

use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\CalificacionRecepcionRespuesta;
use App\Modules\Auditorias\Models\RecepcionParametro;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Calificación de Recepciones (formulario FGH04.15.05-1): Calidad evalúa
 * UNA recepción concreta de un proveedor respondiendo 13 parámetros
 * afirmativo/negativo, y de ahí sale un puntaje sobre 200 y un porcentaje.
 *
 * Autoguardado parámetro por parámetro (igual que AuditoriaService), y al
 * finalizar los puntajes quedan congelados y todo pasa a solo lectura.
 *
 * Los permisos se verifican ACÁ y no en las rutas -> mismo criterio que el
 * resto de los módulos: si mañana alguien agrega un endpoint nuevo y se
 * olvida del gate en la ruta, sigue quedando protegido.
 */
class CalificacionRecepcionService
{
    /**
     * "Cada proveedor una vez al año, máximo 2 veces por año": el sistema
     * agenda UNA (ver AgendaRecepcionService) y Calidad puede hacer una
     * segunda cuando lo considere. La tercera del mismo año calendario se
     * rechaza acá.
     */
    public const MAX_POR_ANIO = 2;

    public function __construct(protected AgendaRecepcionService $agenda)
    {
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /** @return Collection<int, RecepcionParametro> */
    public function listarParametros(): Collection
    {
        return RecepcionParametro::where('Activo', 1)->orderBy('Orden')->get();
    }

    /**
     * Proveedores de la empresa con lo que Calidad necesita para decidir a
     * quién calificar: la fecha que le tocó este año, si es HOY, cuántas
     * calificaciones ya se le hicieron en el año y si todavía se le puede
     * hacer otra.
     */
    public function listarProveedores(Usuario $usuario, int $idEmpresaActiva): Collection
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $anio = (int) now()->year;

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->with('estado')
            ->orderBy('Razon_Social')
            ->get();

        // Un solo query para los conteos y la última calificación de todos,
        // en vez de dos por proveedor dentro del map.
        $calificaciones = CalificacionRecepcion::whereIn('Id_Proveedor', $proveedores->pluck('Id_Proveedor'))
            ->whereYear('Fecha_Recepcion', $anio)
            ->where('Estado', CalificacionRecepcion::ESTADO_FINALIZADA)
            ->get()
            ->groupBy('Id_Proveedor');

        $ultimas = CalificacionRecepcion::whereIn('Id_Proveedor', $proveedores->pluck('Id_Proveedor'))
            ->where('Estado', CalificacionRecepcion::ESTADO_FINALIZADA)
            ->orderByDesc('Fecha_Recepcion')
            ->get()
            ->groupBy('Id_Proveedor');

        // Quiénes tienen entrega HOY y todavía deben su calificación del
        // año. Se resuelve UNA vez para toda la empresa, no por proveedor:
        // el cálculo consulta el calendario de horarios y los pedidos, y
        // hacerlo dentro del map serían dos consultas por fila.
        $tocanHoy = $this->agenda->proveedoresQueTocanHoy($idEmpresaActiva)
            ->pluck('Id_Proveedor')
            ->all();

        return $proveedores->map(function (Proveedor $p) use ($calificaciones, $ultimas, $tocanHoy) {
            $delAnio = $calificaciones->get($p->Id_Proveedor, collect());
            $ultima = $ultimas->get($p->Id_Proveedor, collect())->first();

            return [
                'id_proveedor' => $p->Id_Proveedor,
                'razon_social' => $p->Razon_Social,
                'nombre_comercial' => $p->Nombre_Comercial,
                'ruc' => $p->Ruc,
                'estado' => $p->estado?->Nombre_Estado,
                // Ya no hay "fecha programada": la calificación se hace el
                // día que el proveedor entrega, no un día sorteado del año
                // (ver AgendaRecepcionService).
                'le_toca_hoy' => in_array($p->Id_Proveedor, $tocanHoy, true),
                'calificaciones_del_anio' => $delAnio->count(),
                'puede_calificar' => $delAnio->count() < self::MAX_POR_ANIO,
                'ultima_calificacion' => $ultima ? [
                    'id_calificacion_recepcion' => $ultima->Id_Calificacion_Recepcion,
                    'fecha_recepcion' => $ultima->Fecha_Recepcion?->toDateString(),
                    'porcentaje_obtenido' => $ultima->Porcentaje_Obtenido !== null ? (float) $ultima->Porcentaje_Obtenido : null,
                ] : null,
            ];
        // sortByDesc estable: los que tocan hoy primero, y entre ellos se
        // conserva el orden alfabético que ya trajo la consulta.
        })->sortByDesc('le_toca_hoy')->values();
    }

    /** Historial de calificaciones finalizadas de la empresa activa. */
    public function listarHistorial(Usuario $usuario, int $idEmpresaActiva): Collection
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        return CalificacionRecepcion::where('Id_Empresa', $idEmpresaActiva)
            ->where('Estado', CalificacionRecepcion::ESTADO_FINALIZADA)
            ->with(['proveedor', 'auditor'])
            ->orderByDesc('Fecha_Recepcion')
            ->get()
            ->map(fn (CalificacionRecepcion $c) => [
                'id_calificacion_recepcion' => $c->Id_Calificacion_Recepcion,
                'fecha_recepcion' => $c->Fecha_Recepcion?->toDateString(),
                'proveedor' => $c->proveedor?->Razon_Social,
                'ruc' => $c->proveedor?->Ruc,
                'contacto' => $c->Contacto,
                'auditor' => $c->auditor?->Nombre_Completo,
                'puntaje_obtenido' => (float) $c->Puntaje_Obtenido,
                'puntaje_total_posible' => (float) $c->Puntaje_Total_Posible,
                'porcentaje_obtenido' => (float) $c->Porcentaje_Obtenido,
            ])
            ->values();
    }

    /**
     * Formulario completo: cabecera, los 13 parámetros con su respuesta
     * actual (si ya la tiene) y el resumen de puntaje.
     */
    public function obtenerDetalle(Usuario $usuario, int $idEmpresaActiva, int $idCalificacion): array
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);
        $calificacion = $this->calificacionDeLaEmpresa($idEmpresaActiva, $idCalificacion);

        $calificacion->load(['proveedor.estado', 'auditor', 'respuestas.parametro']);
        $respuestas = $calificacion->respuestas->keyBy('Id_Recepcion_Parametro');

        // En una calificación FINALIZADA los parámetros salen de sus propias
        // respuestas, no del catálogo activo -> si después se desactiva o se
        // reescribe un parámetro, la hoja ya firmada tiene que seguir
        // mostrando exactamente lo que se respondió en su momento.
        $parametros = $calificacion->estaFinalizada()
            ? $calificacion->respuestas->map(fn ($r) => $r->parametro)->filter()->sortBy('Orden')->values()
            : $this->listarParametros();

        return [
            'id_calificacion_recepcion' => $calificacion->Id_Calificacion_Recepcion,
            'estado' => $calificacion->Estado,
            'finalizada' => $calificacion->estaFinalizada(),
            'fecha_recepcion' => $calificacion->Fecha_Recepcion?->toDateString(),
            'contacto' => $calificacion->Contacto,
            'proveedor' => [
                'id_proveedor' => $calificacion->proveedor->Id_Proveedor,
                'razon_social' => $calificacion->proveedor->Razon_Social,
                'nombre_comercial' => $calificacion->proveedor->Nombre_Comercial,
                'ruc' => $calificacion->proveedor->Ruc,
                'estado' => $calificacion->proveedor->estado?->Nombre_Estado,
            ],
            'auditor' => $calificacion->auditor?->Nombre_Completo,
            'parametros' => $parametros->map(function (RecepcionParametro $p) use ($respuestas) {
                $respuesta = $respuestas->get($p->Id_Recepcion_Parametro);

                return [
                    'id_recepcion_parametro' => $p->Id_Recepcion_Parametro,
                    'orden' => $p->Orden,
                    'descripcion' => $p->Descripcion,
                    'puntaje' => (float) $p->Puntaje,
                    'etiqueta_afirmativa' => $p->Etiqueta_Afirmativa,
                    'etiqueta_negativa' => $p->Etiqueta_Negativa,
                    // null = todavía sin responder (distinto de "No").
                    'cumple' => $respuesta ? (bool) $respuesta->Cumple : null,
                    'observacion' => $respuesta?->Observacion,
                ];
            })->values(),
            'resumen' => $this->calcularResumen($calificacion),
        ];
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Retoma el borrador que ya exista para este proveedor (a lo sumo uno a
     * la vez) o crea uno nuevo. Una calificación ya finalizada no se retoma
     * nunca: la próxima vez se arranca una hoja nueva, y así queda el
     * historial de todas las recepciones evaluadas.
     */
    public function obtenerOCrearBorrador(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idProveedor,
        ?string $fechaRecepcion = null,
        ?string $contacto = null
    ): CalificacionRecepcion {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $proveedor = Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->findOrFail($idProveedor);

        $borrador = CalificacionRecepcion::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Estado', CalificacionRecepcion::ESTADO_BORRADOR)
            ->first();

        if ($borrador) {
            return $borrador;
        }

        $this->verificarTopeAnual($proveedor, $fechaRecepcion);

        if ($this->listarParametros()->isEmpty()) {
            throw ValidationException::withMessages([
                'parametros' => ['No hay parámetros de recepción configurados. Corré el seeder RecepcionParametroSeeder.'],
            ]);
        }

        return CalificacionRecepcion::create([
            'Id_Empresa' => $idEmpresaActiva,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $usuario->Id_Usuario,
            'Fecha_Recepcion' => $fechaRecepcion ?? now()->toDateString(),
            'Contacto' => $contacto,
            'Estado' => CalificacionRecepcion::ESTADO_BORRADOR,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    /** Cabecera editable mientras sea borrador (fecha de recepción y contacto). */
    public function actualizarCabecera(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idCalificacion,
        ?string $fechaRecepcion,
        ?string $contacto
    ): void {
        $this->verificarAcceso($usuario, $idEmpresaActiva);
        $calificacion = $this->calificacionDeLaEmpresa($idEmpresaActiva, $idCalificacion);
        $this->verificarEditable($calificacion);

        $calificacion->forceFill(array_filter([
            'Fecha_Recepcion' => $fechaRecepcion,
            'Contacto' => $contacto,
        ], fn ($v) => $v !== null) + [
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();
    }

    /**
     * Guarda la respuesta de UN parámetro (autoguardado). El puntaje no lo
     * manda el cliente: se toma del catálogo -> el navegador no puede
     * inventarse cuántos puntos vale un ítem.
     */
    public function guardarRespuesta(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idCalificacion,
        int $idParametro,
        bool $cumple,
        ?string $observacion
    ): void {
        $this->verificarAcceso($usuario, $idEmpresaActiva);
        $calificacion = $this->calificacionDeLaEmpresa($idEmpresaActiva, $idCalificacion);
        $this->verificarEditable($calificacion);

        $parametro = RecepcionParametro::where('Activo', 1)->findOrFail($idParametro);

        CalificacionRecepcionRespuesta::updateOrCreate(
            [
                'Id_Calificacion_Recepcion' => $calificacion->Id_Calificacion_Recepcion,
                'Id_Recepcion_Parametro' => $parametro->Id_Recepcion_Parametro,
            ],
            [
                'Cumple' => $cumple,
                'Puntaje_Obtenido' => $cumple ? $parametro->Puntaje : 0,
                'Observacion' => $observacion,
                'Fecha_Modificacion' => now(),
            ]
        );
    }

    /**
     * Cierra la calificación: exige que estén los 13 parámetros respondidos
     * y congela los puntajes en la cabecera.
     */
    public function finalizar(Usuario $usuario, int $idEmpresaActiva, int $idCalificacion): void
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);
        $calificacion = $this->calificacionDeLaEmpresa($idEmpresaActiva, $idCalificacion);
        $this->verificarEditable($calificacion);

        $parametros = $this->listarParametros();
        $respuestas = $calificacion->respuestas()->get()->keyBy('Id_Recepcion_Parametro');

        $faltan = $parametros->reject(fn (RecepcionParametro $p) => $respuestas->has($p->Id_Recepcion_Parametro));

        if ($faltan->isNotEmpty()) {
            throw ValidationException::withMessages([
                'parametros' => ["Faltan {$faltan->count()} parámetro(s) por responder."],
            ]);
        }

        $totalPosible = (float) $parametros->sum('Puntaje');
        // Se suma solo sobre los parámetros vigentes -> si alguno se
        // desactivó a mitad del borrador, su respuesta vieja no cuenta.
        $obtenido = (float) $parametros->sum(
            fn (RecepcionParametro $p) => (float) $respuestas->get($p->Id_Recepcion_Parametro)->Puntaje_Obtenido
        );

        $calificacion->forceFill([
            'Estado' => CalificacionRecepcion::ESTADO_FINALIZADA,
            'Puntaje_Total_Posible' => $totalPosible,
            'Puntaje_Obtenido' => $obtenido,
            'Porcentaje_Obtenido' => $totalPosible > 0 ? round(($obtenido / $totalPosible) * 100, 2) : 0,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Resumen en vivo mientras es borrador; los valores congelados una vez
     * finalizada (ver el comentario de la migración sobre por qué no se
     * recalculan siempre).
     */
    protected function calcularResumen(CalificacionRecepcion $calificacion): array
    {
        if ($calificacion->estaFinalizada()) {
            $total = (float) $calificacion->Puntaje_Total_Posible;

            return [
                'puntaje_total_posible' => $total,
                'puntaje_obtenido' => (float) $calificacion->Puntaje_Obtenido,
                'porcentaje_obtenido' => (float) $calificacion->Porcentaje_Obtenido,
                'respondidos' => $calificacion->respuestas->count(),
                'total_parametros' => $calificacion->respuestas->count(),
            ];
        }

        $parametros = $this->listarParametros();
        $respuestas = $calificacion->respuestas->keyBy('Id_Recepcion_Parametro');

        $totalPosible = (float) $parametros->sum('Puntaje');
        $obtenido = 0.0;
        $respondidos = 0;

        foreach ($parametros as $parametro) {
            $respuesta = $respuestas->get($parametro->Id_Recepcion_Parametro);

            if (! $respuesta) {
                continue;
            }

            $respondidos++;
            $obtenido += (float) $respuesta->Puntaje_Obtenido;
        }

        return [
            'puntaje_total_posible' => $totalPosible,
            'puntaje_obtenido' => $obtenido,
            // Sobre el total POSIBLE, no sobre lo respondido hasta ahora ->
            // así el número que se ve mientras se llena es el mismo que va a
            // quedar al finalizar, y no uno que arranca en 100% y baja.
            'porcentaje_obtenido' => $totalPosible > 0 ? round(($obtenido / $totalPosible) * 100, 2) : 0,
            'respondidos' => $respondidos,
            'total_parametros' => $parametros->count(),
        ];
    }

    /**
     * Resuelve la calificación EXIGIENDO que sea de la empresa activa de la
     * sesión. No se usa route binding suelto a propósito: con binding, un
     * usuario que tenga rol en dos empresas podía traer por id una
     * calificación de la otra empresa (la que no está mirando), igual que se
     * evita en Reclamos y en Proveedores.
     */
    protected function calificacionDeLaEmpresa(int $idEmpresaActiva, int $idCalificacion): CalificacionRecepcion
    {
        return CalificacionRecepcion::where('Id_Empresa', $idEmpresaActiva)->findOrFail($idCalificacion);
    }

    protected function verificarTopeAnual(Proveedor $proveedor, ?string $fechaRecepcion): void
    {
        $anio = $fechaRecepcion ? (int) substr($fechaRecepcion, 0, 4) : (int) now()->year;

        $hechas = CalificacionRecepcion::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Estado', CalificacionRecepcion::ESTADO_FINALIZADA)
            ->whereYear('Fecha_Recepcion', $anio)
            ->count();

        if ($hechas >= self::MAX_POR_ANIO) {
            throw ValidationException::withMessages([
                'proveedor' => [
                    "Este proveedor ya tiene ".self::MAX_POR_ANIO." calificaciones de recepción en {$anio}, que es el máximo por año.",
                ],
            ]);
        }
    }

    protected function verificarEditable(CalificacionRecepcion $calificacion): void
    {
        if ($calificacion->estaFinalizada()) {
            throw ValidationException::withMessages([
                'estado' => ['Esta calificación ya fue finalizada, no se puede modificar.'],
            ]);
        }
    }

    /** Mismos roles que el resto de Auditorías: Sistemas, Admin y Calidad. */
    /**
     * Calificar recepciones: Sistemas y Calidad. Admin quedó FUERA
     * (decisión del negocio, 1-sep-2026): no califica ni auditorías ni
     * recepciones, igual que ya pasaba con AuditoriaService.
     *
     * Admin NO se queda sin ver los resultados: la nota de auditoría y la
     * de recepción son dos de los cinco componentes de la calificación
     * global, y esa la sigue viendo en Reportes -> Calificación de
     * proveedores. Lo que pierde es el permiso de PONER la nota, no el de
     * leerla.
     */
    protected function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa)
            || $usuario->esCalidad($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para acceder a la calificación de recepciones.');
        }
    }
}
