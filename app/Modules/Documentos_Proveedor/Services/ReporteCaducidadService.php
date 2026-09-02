<?php

namespace App\Modules\Documentos_Proveedor\Services;

use App\Modules\Auth\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Reporte de caducidad de documentos: qué documentos vencen, de quién y
 * cuándo, agrupados por urgencia.
 *
 * Es distinto del aviso automático por correo (VencimientoDocumentosService,
 * que avisa 30 días antes y suspende a los 15 de vencido): eso reacciona
 * documento por documento, esto es la foto completa para poder priorizar a
 * quién perseguir primero.
 */
class ReporteCaducidadService
{
    /**
     * Tramos de urgencia. El ORDEN IMPORTA: se evalúan de arriba hacia
     * abajo y gana el primero que cumple, así que van del más urgente al
     * menos urgente.
     *
     * 'hasta_dias' es el tope de días restantes del tramo; null = sin tope
     * (todo lo que quede por encima del anterior).
     *
     * Los cortes en 7, 15 y 30 no son arbitrarios: coinciden con los plazos
     * que ya usa el ciclo de avisos (DIAS_ENTRE_AVISOS = 7,
     * DIAS_GRACIA_SUSPENSION = 15, DIAS_PRIMER_AVISO = 30), así el reporte y
     * los correos hablan de los mismos umbrales.
     */
    public const TRAMOS = [
        [
            'clave' => 'vencido',
            'etiqueta' => 'Ya vencidos',
            'descripcion' => 'La fecha de caducidad ya pasó. El proveedor puede quedar suspendido.',
            'hasta_dias' => -1,
        ],
        [
            'clave' => 'critico',
            'etiqueta' => 'Vencen en 7 días o menos',
            'descripcion' => 'Requieren gestión inmediata.',
            'hasta_dias' => 7,
        ],
        [
            'clave' => 'urgente',
            'etiqueta' => 'Vencen en 15 días o menos',
            'descripcion' => 'Conviene pedir la renovación esta semana.',
            'hasta_dias' => 15,
        ],
        [
            'clave' => 'proximo',
            'etiqueta' => 'Vencen dentro del mes',
            'descripcion' => 'Ya recibieron el primer aviso automático.',
            'hasta_dias' => 30,
        ],
        [
            'clave' => 'holgado',
            'etiqueta' => 'Más de un mes para vencer',
            'descripcion' => 'Sin acción pendiente por ahora.',
            'hasta_dias' => null,
        ],
    ];

    /**
     * Ver el reporte: Admin, Calidad y Sistemas (decisión del negocio,
     * 1-sep-2026). Compras queda fuera: la documentación no es su área.
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCalidad($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el reporte de caducidad de documentos.');
        }
    }

    /** A qué tramo pertenece una cantidad de días restantes. */
    public static function tramoDe(int $diasRestantes): string
    {
        foreach (self::TRAMOS as $tramo) {
            if ($tramo['hasta_dias'] === null || $diasRestantes <= $tramo['hasta_dias']) {
                return $tramo['clave'];
            }
        }

        return 'holgado';
    }

    /**
     * Documentos con fecha de caducidad de los proveedores de la empresa.
     *
     * Solo entran los que TIENEN fecha: un RUC no vence nunca, así que
     * listarlo acá sería ruido. Y solo proveedores activos: la
     * documentación de uno dado de baja ya no hay que perseguirla.
     *
     * Los días restantes se calculan con DATEDIFF sobre FECHAS (no sobre
     * marcas de tiempo) para que "vence hoy" dé 0 y no un negativo por unas
     * horas de diferencia.
     *
     * Y el "hoy" se manda desde PHP en vez de usar GETDATE(): el servidor de
     * base de datos está en OTRA ZONA HORARIA que la aplicación (verificado
     * el 1-sep-2026: la base marcaba las 01:58 del día 2 cuando acá eran las
     * 18:58 del día 1). Con GETDATE() todos los plazos salían corridos un
     * día, y un documento que vencía hoy aparecía como ya vencido.
     */
    public function listar(Usuario $usuario, int $idEmpresa): array
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        $hoy = now()->toDateString();

        $filas = DB::table('Documento_Proveedor as dp')
            ->join('Tipo_Documento as td', 'td.Id_Tipo_Documento', '=', 'dp.Id_Tipo_Documento')
            ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'dp.Id_Proveedor')
            ->where('p.Id_Empresa', $idEmpresa)
            ->where('p.Activo', 1)
            ->where('dp.Activo', 1)
            ->whereNotNull('dp.Fecha_Caducidad')
            ->select([
                'dp.Id_Documento_Proveedor as id_documento_proveedor',
                'p.Id_Proveedor as id_proveedor',
                'p.Razon_Social as razon_social',
                'p.Nombre_Comercial as nombre_comercial',
                'p.Ruc as ruc',
                'td.Nombre_Documento as documento',
                'dp.Fecha_Caducidad as fecha_caducidad',
                'dp.Estado_Calificacion as estado_calificacion',
                DB::raw('DATEDIFF(day, CONVERT(date, ?, 120), dp.Fecha_Caducidad) as dias_restantes'),
            ])
            ->addBinding($hoy, 'select')
            ->orderBy('dp.Fecha_Caducidad')
            ->get();

        return $filas->map(function ($fila) {
            $dias = (int) $fila->dias_restantes;

            return [
                'id_documento_proveedor' => (int) $fila->id_documento_proveedor,
                'id_proveedor' => (int) $fila->id_proveedor,
                'razon_social' => $fila->razon_social,
                'nombre_comercial' => $fila->nombre_comercial,
                'ruc' => $fila->ruc,
                'documento' => $fila->documento,
                'fecha_caducidad' => $fila->fecha_caducidad,
                'estado_calificacion' => $fila->estado_calificacion,
                'dias_restantes' => $dias,
                'tramo' => self::tramoDe($dias),
            ];
        })->all();
    }
}
