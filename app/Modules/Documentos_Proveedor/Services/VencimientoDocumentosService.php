<?php

namespace App\Modules\Documentos_Proveedor\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\Configuracion;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\HistorialEstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\ProveedorService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Ciclo de vida de un documento que vence:
 *
 *   -30 días  primer aviso por correo (proveedor + Calidad + Admin)
 *   cada 7 d  reaviso, mientras siga sin reemplazarse
 *    día 0    vence (el proveedor TODAVÍA puede entrar y reemplazarlo)
 *   +15 días  el Proveedor DE ESA EMPRESA pasa a Suspendido
 *
 * Los 15 días de gracia después del vencimiento son decisión del negocio
 * (no un mes): ver DIAS_GRACIA_SUSPENSION.
 *
 * La suspensión afecta SOLO al Proveedor de la empresa dueña del documento.
 * El usuario externo sigue entrando con normalidad a las otras empresas del
 * grupo donde esté al día -> el corte por empresa lo aplica el middleware
 * EmpresaActiva, y el login solo se niega del todo si no le queda ninguna
 * empresa disponible (ver AuthService).
 */
class VencimientoDocumentosService
{
    /** Días antes del vencimiento en que sale el primer aviso. */
    public const DIAS_PRIMER_AVISO = 30;

    /** Cada cuántos días se reitera el aviso mientras no se reemplace. */
    public const DIAS_ENTRE_AVISOS = 7;

    /** Días DESPUÉS del vencimiento antes de suspender al proveedor. */
    public const DIAS_GRACIA_SUSPENSION = 15;

    /**
     * Interruptor de la suspensión automática. Vive en la tabla
     * Configuracion (la misma de login_imagen_url) y no en el .env a
     * propósito: así Sistemas lo prende y apaga desde la pantalla de
     * Configuraciones, sin entrar al servidor ni limpiar cachés.
     *
     * Los avisos por correo NO dependen de este interruptor: avisar nunca
     * hace daño, suspender sí.
     */
    public const CLAVE_SUSPENSION_AUTOMATICA = 'suspension_automatica_documentos';

    public function __construct(protected ProveedorService $proveedorService)
    {
    }

    public function suspensionAutomaticaActiva(): bool
    {
        // Por defecto ENCENDIDA: si la clave no existe todavía, se asume
        // que el ciclo completo está vigente.
        return Configuracion::obtener(self::CLAVE_SUSPENSION_AUTOMATICA, '1') === '1';
    }

    public function definirSuspensionAutomatica(bool $activa, int $idUsuario): void
    {
        Configuracion::establecer(self::CLAVE_SUSPENSION_AUTOMATICA, $activa ? '1' : '0', $idUsuario);
    }

    // ------------------------------------------------------------------
    // Avisos
    // ------------------------------------------------------------------

    /**
     * Documentos activos a los que hay que avisarles HOY: los que están a
     * 30 días o menos del vencimiento (incluidos los ya vencidos, mientras
     * no se haya suspendido al proveedor) y que no recibieron aviso en los
     * últimos 7 días.
     *
     * Un documento ya Aprobado y con fecha lejana no entra; uno rechazado
     * tampoco se excluye -> si está cargado y vence, hay que avisar igual.
     *
     * @return Collection<int, DocumentoProveedor>
     */
    public function documentosParaAvisar(?Carbon $hoy = null): Collection
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $limiteAviso = $hoy->copy()->addDays(self::DIAS_PRIMER_AVISO);

        return DocumentoProveedor::where('Activo', 1)
            ->whereNotNull('Fecha_Caducidad')
            ->whereDate('Fecha_Caducidad', '<=', $limiteAviso->toDateString())
            ->with(['proveedor.empresa', 'tipoDocumento'])
            ->get()
            ->filter(function (DocumentoProveedor $doc) use ($hoy) {
                // Ya suspendido por ESTE vencimiento: dejar de insistirle por
                // correo, la pelota pasó a Admin.
                if ($this->diasDeAtraso($doc, $hoy) > self::DIAS_GRACIA_SUSPENSION) {
                    return false;
                }

                $ultimo = $doc->Fecha_Ultima_Notificacion;

                // Nunca avisado -> primer aviso.
                if (! $ultimo) {
                    return true;
                }

                // Reaviso semanal.
                return $ultimo->copy()->startOfDay()->diffInDays($hoy, false) >= self::DIAS_ENTRE_AVISOS;
            })
            ->values();
    }

    public function marcarAvisado(DocumentoProveedor $documento): void
    {
        $documento->forceFill([
            'Notificacion_Enviada' => true,
            'Fecha_Ultima_Notificacion' => now(),
        ])->save();
    }

    /** Días vencido (negativo si todavía no venció). */
    public function diasDeAtraso(DocumentoProveedor $documento, ?Carbon $hoy = null): int
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();

        return $documento->Fecha_Caducidad->copy()->startOfDay()->diffInDays($hoy, false);
    }

    // ------------------------------------------------------------------
    // Suspensión
    // ------------------------------------------------------------------

    /**
     * Proveedores que hoy corresponde suspender: tienen al menos un
     * documento activo vencido hace 15 días o más, y todavía no están
     * Suspendidos.
     *
     * @return Collection<int, Proveedor>
     */
    public function proveedoresParaSuspender(?Carbon $hoy = null): Collection
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $limite = $hoy->copy()->subDays(self::DIAS_GRACIA_SUSPENSION);

        $idsProveedores = DocumentoProveedor::where('Activo', 1)
            ->whereNotNull('Fecha_Caducidad')
            ->whereDate('Fecha_Caducidad', '<=', $limite->toDateString())
            ->pluck('Id_Proveedor')
            ->unique();

        if ($idsProveedores->isEmpty()) {
            return new Collection();
        }

        return Proveedor::whereIn('Id_Proveedor', $idsProveedores)
            ->where('Activo', 1)
            ->where('Id_Estado_Proveedor', '!=', EstadoProveedor::SUSPENDIDO)
            ->with('empresa')
            ->get();
    }

    /**
     * Suspende al proveedor dejando constancia en Historial_Estado_Proveedor
     * (de ahí se recupera después a qué estado volver al reactivarlo).
     */
    public function suspender(Proveedor $proveedor, array $documentosVencidos, ?int $idUsuarioEjecutor = null): void
    {
        $nombres = collect($documentosVencidos)->implode(', ');

        $this->proveedorService->cambiarEstado(
            $proveedor,
            EstadoProveedor::SUSPENDIDO,
            'Suspensión automática por documentación vencida hace más de '
                . self::DIAS_GRACIA_SUSPENSION . ' días: ' . ($nombres !== '' ? $nombres : 'documentación obligatoria'),
            $idUsuarioEjecutor
        );
    }

    /**
     * Documentos activos de un proveedor vencidos hace 15 días o más ->
     * los que justifican la suspensión, para nombrarlos en el historial y
     * en el correo.
     *
     * @return array<int, string>
     */
    public function nombresDocumentosVencidos(Proveedor $proveedor, ?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $limite = $hoy->copy()->subDays(self::DIAS_GRACIA_SUSPENSION);

        return DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->whereNotNull('Fecha_Caducidad')
            ->whereDate('Fecha_Caducidad', '<=', $limite->toDateString())
            ->with('tipoDocumento')
            ->get()
            ->map(fn (DocumentoProveedor $d) => $d->tipoDocumento->Nombre_Documento ?? 'Documento')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ¿Este proveedor está suspendido por documentación? Lo consulta el
     * middleware EmpresaActiva y el login.
     */
    public function estaSuspendido(Proveedor $proveedor): bool
    {
        return (int) $proveedor->Id_Estado_Proveedor === EstadoProveedor::SUSPENDIDO;
    }

    /**
     * Levanta la suspensión devolviendo al proveedor al estado que tenía
     * ANTES de ser suspendido (se lee del historial en vez de asumir
     * "Aprobado": pudo haber sido suspendido siendo Aspirante).
     */
    public function levantarSuspension(Proveedor $proveedor, ?int $idUsuarioEjecutor = null): void
    {
        if (! $this->estaSuspendido($proveedor)) {
            return;
        }

        $ultimaSuspension = HistorialEstadoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Id_Estado_Nuevo', EstadoProveedor::SUSPENDIDO)
            ->orderByDesc('Fecha_Cambio')
            ->first();

        $estadoAnterior = (int) ($ultimaSuspension->Id_Estado_Anterior ?? EstadoProveedor::APROBADO);

        $this->proveedorService->cambiarEstado(
            $proveedor,
            $estadoAnterior,
            'Se levanta la suspensión: documentación regularizada.',
            $idUsuarioEjecutor
        );
    }

    /**
     * Usuarios internos de una empresa que deben enterarse (Calidad y
     * Admin, según el pedido). Mismo criterio que
     * SolicitudCambioPrecioService::notificarAdminsYCalidad.
     *
     * @return Collection<int, Usuario>
     */
    public function internosANotificar(int $idEmpresa): Collection
    {
        return Usuario::where('Activo', 1)
            ->whereHas('usuarioEmpresas', function ($q) use ($idEmpresa) {
                $q->where('Id_Empresa', $idEmpresa)
                    ->where('Activo', true)
                    ->whereHas('rol', fn ($r) => $r->whereIn('Nombre_Rol', ['Calidad', 'Admin']));
            })
            ->get();
    }
}
