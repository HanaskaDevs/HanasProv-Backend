<?php

namespace App\Modules\Documentos_Proveedor\Services;

use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Recordatorio de documentación incompleta (pedido del usuario, 29-oct-2026).
 *
 * El caso: un proveedor de X documentos obligatorios sube uno solo y se
 * olvida del resto. Si pasan 7 días (config portal.dias_recordatorio_
 * documentacion) sin que suba ningún documento nuevo, se le manda un correo
 * con la lista de los que le faltan.
 *
 * A QUIÉN:
 *  - Proveedores activos que NO estén rechazados.
 *  - Que ya hayan subido AL MENOS UN documento: al que no subió ninguno
 *    todavía no se le recuerda nada (es otro caso: nunca empezó).
 *  - Que tengan obligatorios sin subir (mismo criterio de "faltante" que
 *    DocumentoProveedorService::pendientesDeDocumentacion: obligatorio que
 *    le corresponde y del que no hay ningún documento activo).
 *  - Cuyo último documento subido tenga 7 días o más.
 *
 * CADA CUÁNTO: una vez, y si sigue igual, otra vez cada 7 días
 * (Proveedor.Fecha_Aviso_Documentacion_Incompleta). Si sube un documento
 * nuevo, el plazo vuelve a contar desde esa subida.
 */
class RecordatorioDocumentacionService
{
    public function __construct(protected DocumentoProveedorService $documentoService)
    {
    }

    public function diasSinNovedad(): int
    {
        return max(1, (int) config('portal.dias_recordatorio_documentacion', 7));
    }

    /**
     * Proveedores a los que hoy corresponde recordarles, cada uno con lo que
     * le falta y la fecha de su último documento.
     *
     * @return Collection<int, array{proveedor: Proveedor, faltantes: list<string>, ultimo_documento: Carbon}>
     */
    public function proveedoresParaRecordar(?Carbon $hoy = null): Collection
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $dias = $this->diasSinNovedad();

        // Última subida por proveedor, contando también los documentos ya
        // reemplazados (Activo = 0): reemplazar uno también es una novedad.
        $ultimaSubida = DocumentoProveedor::query()
            ->groupBy('Id_Proveedor')
            ->selectRaw('Id_Proveedor, MAX(Fecha_Creacion) AS ultima')
            ->pluck('ultima', 'Id_Proveedor');

        $conAlgunoActivo = DocumentoProveedor::where('Activo', 1)
            ->distinct()
            ->pluck('Id_Proveedor')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($conAlgunoActivo === []) {
            return collect();
        }

        return Proveedor::whereIn('Id_Proveedor', $conAlgunoActivo)
            ->where('Activo', 1)
            ->where('Id_Estado_Proveedor', '!=', EstadoProveedor::RECHAZADO)
            ->whereNotNull('Email')
            ->with('empresa')
            ->get()
            ->map(function (Proveedor $proveedor) use ($ultimaSubida, $hoy, $dias) {
                $ultima = $ultimaSubida[$proveedor->Id_Proveedor] ?? null;

                if ($ultima === null) {
                    return null;
                }

                $ultima = Carbon::parse($ultima);

                if ($ultima->copy()->startOfDay()->diffInDays($hoy, false) < $dias) {
                    return null;
                }

                $avisado = $proveedor->Fecha_Aviso_Documentacion_Incompleta;

                if ($avisado && $avisado->copy()->startOfDay()->diffInDays($hoy, false) < $dias) {
                    return null;
                }

                $faltantes = $this->documentoService->pendientesDeDocumentacion($proveedor)['faltantes'];

                if ($faltantes === []) {
                    return null;
                }

                return [
                    'proveedor' => $proveedor,
                    'faltantes' => $faltantes,
                    'ultimo_documento' => $ultima,
                ];
            })
            ->filter()
            ->values();
    }

    public function marcarAvisado(Proveedor $proveedor): void
    {
        $proveedor->forceFill(['Fecha_Aviso_Documentacion_Incompleta' => now()])->save();
    }
}
