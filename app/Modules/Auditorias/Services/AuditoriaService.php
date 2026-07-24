<?php

namespace App\Modules\Auditorias\Services;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\AuditoriaPregunta;
use App\Modules\Auditorias\Models\AuditoriaRespuesta;
use App\Modules\Auditorias\Models\TipoAuditoria;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuditoriaService
{
    public function listarTiposAuditoria(Usuario $usuario, int $idEmpresa): Collection
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        return TipoAuditoria::where('Activo', true)->orderBy('Orden')->get();
    }

    public function listarProveedoresParaAuditoria(Usuario $usuario, int $idEmpresa): Collection
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', true)
            ->with(['estado', 'clases'])
            ->orderBy('Razon_Social')
            ->get()
            ->map(fn($p) => [
                'id_proveedor' => $p->Id_Proveedor,
                'razon_social' => $p->Razon_Social,
                'nombre_comercial' => $p->Nombre_Comercial,
                'ruc' => $p->Ruc,
                'estado' => $p->estado?->Nombre_Estado,
                'clases' => $p->clases->pluck('Nombre_Clase')->values(),
            ])
            ->values();
    }

    /**
     * Retoma la auditoría en Borrador que ya exista para esta combinación
     * empresa+tipo+proveedor, o crea una nueva si no hay ninguna abierta.
     * Una vez "Finalizada", una auditoría vieja no vuelve a aparecer acá ->
     * la próxima vez se crea una nueva (permite re-auditar sin perder el
     * historial de la anterior).
     */
    public function obtenerOCrearAuditoria(Usuario $usuario, int $idEmpresa, int $idTipoAuditoria, int $idProveedor): Auditoria
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)->findOrFail($idProveedor);
        TipoAuditoria::findOrFail($idTipoAuditoria);

        $borrador = Auditoria::where('Id_Empresa', $idEmpresa)
            ->where('Id_Tipo_Auditoria', $idTipoAuditoria)
            ->where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Estado', 'Borrador')
            ->first();

        if ($borrador) {
            return $borrador;
        }

        return Auditoria::create([
            'Id_Empresa' => $idEmpresa,
            'Id_Tipo_Auditoria' => $idTipoAuditoria,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $usuario->Id_Usuario,
            'Fecha_Auditoria' => now()->toDateString(),
            'Estado' => 'Borrador',
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    /**
     * Arma el formulario completo: secciones -> preguntas, cada una con su
     * respuesta actual (si existe), datos básicos del proveedor, y el
     * resumen de puntajes (A, B, C, D, E) recalculado en vivo.
     */
    public function obtenerDetalle(Usuario $usuario, Auditoria $auditoria): array
    {
        $this->verificarAcceso($usuario, $auditoria->Id_Empresa);

        $auditoria->load(['proveedor.estado', 'proveedor.clases', 'tipoAuditoria', 'auditor']);

        $secciones = $auditoria->tipoAuditoria
            ->secciones()
            ->where('Activo', true)
            ->with(['preguntas' => fn($q) => $q->where('Activo', true)])
            ->get();

        $respuestasPorPregunta = $auditoria->respuestas()->get()->keyBy('Id_Auditoria_Pregunta');

        $seccionesConRespuesta = $secciones->map(function ($seccion) use ($respuestasPorPregunta) {
            return [
                'id_auditoria_seccion' => $seccion->Id_Auditoria_Seccion,
                'nombre_seccion' => $seccion->Nombre_Seccion,
                'preguntas' => $seccion->preguntas->map(function ($pregunta) use ($respuestasPorPregunta) {
                    $respuesta = $respuestasPorPregunta->get($pregunta->Id_Auditoria_Pregunta);

                    return [
                        'id_auditoria_pregunta' => $pregunta->Id_Auditoria_Pregunta,
                        'subseccion' => $pregunta->Subseccion,
                        'numero' => $pregunta->Numero,
                        'descripcion' => $pregunta->Descripcion,
                        'puntaje_max' => (float) $pregunta->Puntaje_Max,
                        'puntaje_obtenido' => $respuesta?->Puntaje_Obtenido !== null ? (float) $respuesta->Puntaje_Obtenido : null,
                        'no_aplica' => (bool) ($respuesta?->No_Aplica ?? false),
                        'observacion' => $respuesta?->Observacion,
                    ];
                })->values(),
            ];
        })->values();

        return [
            'id_auditoria' => $auditoria->Id_Auditoria,
            'estado' => $auditoria->Estado,
            'fecha_auditoria' => $auditoria->Fecha_Auditoria?->toDateString(),
            'tipo_auditoria' => [
                'id_tipo_auditoria' => $auditoria->tipoAuditoria->Id_Tipo_Auditoria,
                'nombre' => $auditoria->tipoAuditoria->Nombre,
            ],
            'proveedor' => [
                'id_proveedor' => $auditoria->proveedor->Id_Proveedor,
                'razon_social' => $auditoria->proveedor->Razon_Social,
                'nombre_comercial' => $auditoria->proveedor->Nombre_Comercial,
                'ruc' => $auditoria->proveedor->Ruc,
                'estado' => $auditoria->proveedor->estado?->Nombre_Estado,
                'clases' => $auditoria->proveedor->clases->pluck('Nombre_Clase')->values(),
            ],
            'auditor' => $auditoria->auditor?->Nombre_Completo,
            'secciones' => $seccionesConRespuesta,
            'resumen' => $this->calcularResumen($auditoria),
        ];
    }

    /**
     * Guarda/actualiza la respuesta de UNA pregunta (autoguardado por
     * campo). Puntaje_Obtenido debe estar entre 0 y el Puntaje_Max de esa
     * pregunta -> si viene "No aplica", el puntaje se ignora (se guarda en
     * null) y no participa en el cálculo del resumen.
     */
    public function guardarRespuesta(
        Usuario $usuario,
        Auditoria $auditoria,
        int $idPregunta,
        ?float $puntajeObtenido,
        bool $noAplica,
        ?string $observacion
    ): void {
        $this->verificarAcceso($usuario, $auditoria->Id_Empresa);

        if ($auditoria->Estado === 'Finalizada') {
            throw ValidationException::withMessages([
                'estado' => ['Esta auditoría ya fue finalizada, no se puede modificar.'],
            ]);
        }

        $pregunta = AuditoriaPregunta::whereHas('seccion', function ($q) use ($auditoria) {
            $q->where('Id_Tipo_Auditoria', $auditoria->Id_Tipo_Auditoria);
        })->findOrFail($idPregunta);

        if (! $noAplica && $puntajeObtenido !== null) {
            if ($puntajeObtenido < 0 || $puntajeObtenido > (float) $pregunta->Puntaje_Max) {
                throw ValidationException::withMessages([
                    'puntaje_obtenido' => ["El puntaje debe estar entre 0 y {$pregunta->Puntaje_Max}."],
                ]);
            }
        }

        AuditoriaRespuesta::updateOrCreate(
            ['Id_Auditoria' => $auditoria->Id_Auditoria, 'Id_Auditoria_Pregunta' => $idPregunta],
            [
                'Puntaje_Obtenido' => $noAplica ? null : $puntajeObtenido,
                'No_Aplica' => $noAplica,
                'Observacion' => $observacion,
                'Fecha_Modificacion' => now(),
            ]
        );
    }

   public function finalizar(Usuario $usuario, Auditoria $auditoria): void
    {
        $this->verificarAcceso($usuario, $auditoria->Id_Empresa);

        if ($auditoria->Estado === 'Finalizada') {
            throw ValidationException::withMessages([
                'estado' => ['Esta auditoría ya fue finalizada.'],
            ]);
        }

        $totalPreguntas = AuditoriaPregunta::whereHas('seccion', function ($q) use ($auditoria) {
            $q->where('Id_Tipo_Auditoria', $auditoria->Id_Tipo_Auditoria)->where('Activo', true);
        })->where('Activo', true)->count();

        $totalRespondidas = $auditoria->respuestas()
            ->where(function ($q) {
                $q->where('No_Aplica', true)->orWhereNotNull('Puntaje_Obtenido');
            })
            ->count();

        if ($totalRespondidas < $totalPreguntas) {
            $faltan = $totalPreguntas - $totalRespondidas;
            throw ValidationException::withMessages([
                'estado' => ["Faltan {$faltan} pregunta(s) por calificar o marcar como \"No aplica\"."],
            ]);
        }

        $auditoria->forceFill([
            'Estado' => 'Finalizada',
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();
    }

    protected function calcularResumen(Auditoria $auditoria): array
    {
        $preguntas = AuditoriaPregunta::whereHas('seccion', function ($q) use ($auditoria) {
            $q->where('Id_Tipo_Auditoria', $auditoria->Id_Tipo_Auditoria)->where('Activo', true);
        })->where('Activo', true)->get(['Id_Auditoria_Pregunta', 'Puntaje_Max']);

        $respuestas = $auditoria->respuestas()->get()->keyBy('Id_Auditoria_Pregunta');

        $totalPosible = (float) $preguntas->sum('Puntaje_Max');
        $totalNoAplica = 0.0;
        $totalObtenido = 0.0;

        foreach ($preguntas as $pregunta) {
            $respuesta = $respuestas->get($pregunta->Id_Auditoria_Pregunta);

            if (! $respuesta) {
                continue;
            }

            if ($respuesta->No_Aplica) {
                $totalNoAplica += (float) $pregunta->Puntaje_Max;
            } elseif ($respuesta->Puntaje_Obtenido !== null) {
                $totalObtenido += (float) $respuesta->Puntaje_Obtenido;
            }
        }

        $totalAplica = $totalPosible - $totalNoAplica;
        $porcentaje = $totalAplica > 0 ? round(($totalObtenido / $totalAplica) * 100, 2) : 0.0;

        return [
            'puntaje_total_posible' => $totalPosible,
            'puntaje_no_aplica' => $totalNoAplica,
            'puntaje_total_aplica' => $totalAplica,
            'puntaje_total_obtenido' => $totalObtenido,
            'porcentaje_cumplimiento' => $porcentaje,
        ];
    }

    protected function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCalidad($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para acceder al módulo de auditorías.');
        }
    }
}
