<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Ficha_Productos\Mail\AvisoProductoMail;
use App\Modules\Ficha_Productos\Mail\PendientesCalidadMail;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Los tres correos del circuito de aprobación de productos, en un solo
 * lugar (23-sep-2026):
 *
 *   1. el proveedor envía un producto -> avisa a COMPRAS
 *   2. Compras lo aprueba             -> avisa a CALIDAD, con todo lo que
 *                                        ese proveedor tiene pendiente
 *   3. Compras lo rechaza o elimina   -> avisa al PROVEEDOR, con el motivo
 *
 * NINGÚN AVISO PUEDE FRENAR LA ACCIÓN. Todos van dentro de un try/catch:
 * si el correo falla (servidor caído, dirección inválida), la decisión que
 * tomó la persona YA quedó guardada y acá solo se registra el error. Es el
 * mismo criterio que el posteo a Business Central: un problema de
 * mensajería no puede revertir una decisión del negocio.
 *
 * Mail::to(...)->queue() y no send(): salen por el worker, no dentro de la
 * petición (ver routes/console.php).
 */
class AvisoProductosService
{
    /**
     * Paso 1: el proveedor mandó productos a aprobar.
     *
     * Va a los usuarios con rol Compras de esa empresa MÁS las direcciones
     * fijas de config('portal.copias_aviso_compras') -las jefaturas, que no
     * tienen el rol en el portal pero quieren enterarse igual-.
     *
     * @param  \Illuminate\Support\Collection<int, Producto>  $productos
     */
    public function avisarACompras($productos, Usuario $proveedorUsuario): void
    {
        if ($productos->isEmpty()) {
            return;
        }

        $primero = $productos->first();
        $proveedor = $primero->proveedor;
        $destinatarios = $this->correosDelRol($proveedor->Id_Empresa, 'Compras');

        if (empty($destinatarios)) {
            Log::warning('Aviso a Compras: no hay a quién avisar.', [
                'id_empresa' => $proveedor->Id_Empresa,
            ]);

            return;
        }

        $cuantos = $productos->count();
        $nombres = $productos->pluck('Nombre_Producto')->take(5)->implode(', ');
        $resto = $cuantos > 5 ? ' y '.($cuantos - 5).' más' : '';

        $this->enviar(
            $destinatarios,
            new AvisoProductoMail(
                asunto: $cuantos === 1
                    ? "Producto pendiente de revisión: {$primero->Nombre_Producto}"
                    : "{$cuantos} productos pendientes de revisión de {$this->nombreDe($proveedor)}",
                mensaje: 'El proveedor <strong>'.e($this->nombreDe($proveedor)).'</strong> envió '
                    .($cuantos === 1 ? 'un producto' : "{$cuantos} productos")
                    .' a aprobación. Compras debe revisarlo antes de que pase a Calidad.',
                nombreProveedor: $this->nombreDe($proveedor),
                nombreProducto: $nombres.$resto,
                urlAccion: $this->url('/productos-revision'),
                textoAccion: 'Revisar los productos',
            ),
            contexto: ['paso' => 'compras', 'id_usuario_proveedor' => $proveedorUsuario->Id_Usuario]
        );
    }

    /**
     * Paso 2: Compras dejó pasar uno o varios productos de UN proveedor ->
     * le toca a Calidad.
     *
     * UN SOLO CORREO CON TODO (14-oct-2026). No avisa solo "este producto":
     * le cuenta a Calidad todo lo que ese proveedor tiene esperándola, en
     * el orden en que se trabaja -ficha, documentos, productos-, para que
     * lo resuelva de una sola pasada. La ficha y los documentos no tienen
     * aviso propio a propósito: este es el único correo que le llega.
     *
     * Si Compras aprueba varios productos de una vez (selección múltiple),
     * se llama una vez con todos y sale un único correo.
     *
     * @param  \Illuminate\Support\Collection<int, Producto>  $aprobados
     */
    public function avisarACalidad(Proveedor $proveedor, $aprobados, Usuario $quienAprobo): void
    {
        if ($aprobados->isEmpty()) {
            return;
        }

        // Calidad también resuelve la etapa de Compras (16-oct-2026): si el
        // que aprobó ES de Calidad, el producto igual pasa a su etapa, pero
        // avisarle a Calidad que tiene pendiente lo que ella misma acaba
        // de mover sería ruido.
        if ($quienAprobo->esCalidad($proveedor->Id_Empresa)) {
            return;
        }

        $destinatarios = $this->correosDelRol($proveedor->Id_Empresa, 'Calidad');

        if (empty($destinatarios)) {
            Log::warning('Aviso a Calidad: no hay a quién avisar.', [
                'id_empresa' => $proveedor->Id_Empresa,
                'id_proveedor' => $proveedor->Id_Proveedor,
                'productos' => $aprobados->pluck('Id_Producto')->all(),
            ]);

            return;
        }

        $nombre = $this->nombreDe($proveedor);
        $cuantos = $aprobados->count();

        $this->enviar(
            $destinatarios,
            new PendientesCalidadMail(
                asunto: $cuantos === 1
                    ? "Pendiente de Calidad: {$nombre} ({$aprobados->first()->Nombre_Producto})"
                    : "Pendiente de Calidad: {$nombre} ({$cuantos} productos)",
                nombreProveedor: $nombre,
                rucProveedor: $proveedor->Ruc ?: null,
                quienAprobo: $quienAprobo->Nombre_Completo ?: $quienAprobo->Email,
                panorama: $this->panoramaDelProveedor($proveedor, $aprobados),
                // Abre la calificación de ESE proveedor directamente.
                urlAccion: $this->url('/proveedores?calificar='.$proveedor->Id_Proveedor),
            ),
            contexto: [
                'paso' => 'calidad',
                'id_proveedor' => $proveedor->Id_Proveedor,
                'productos' => $aprobados->pluck('Id_Producto')->all(),
                'aprobo' => $quienAprobo->Id_Usuario,
            ]
        );
    }

    /**
     * En qué está el proveedor, parte por parte, con los mismos cuatro
     * estados que muestra la pantalla de Detalle de Proveedores
     * (estadoRegistro.ts en el front): incompleta, pendiente, rechazada,
     * aprobada. Si el correo dijera una cosa y la pantalla otra, Calidad
     * dejaría de confiar en las dos.
     *
     * @param  \Illuminate\Support\Collection<int, Producto>  $aprobados
     */
    public function panoramaDelProveedor(Proveedor $proveedor, $aprobados): array
    {
        $proveedor->loadMissing('calificacionesCampos');

        // --- Ficha ---
        $porcentaje = (int) $proveedor->Porcentaje_Completado_Ficha;
        $estadoFicha = $proveedor->estadoGeneralCalificacionFicha();

        $ficha = match (true) {
            $porcentaje < 100 => ['estado' => 'incompleta', 'etiqueta' => 'Incompleta',
                'detalle' => "El proveedor la tiene cargada al {$porcentaje}%. Todavía no se puede calificar."],
            $estadoFicha === 'Aprobado' => ['estado' => 'aprobada', 'etiqueta' => 'Aprobada', 'detalle' => null],
            $estadoFicha === 'Rechazado' => ['estado' => 'rechazada', 'etiqueta' => 'Rechazada',
                'detalle' => 'Se calificó con observaciones; se espera que el proveedor corrija.'],
            default => ['estado' => 'pendiente', 'etiqueta' => 'Por calificar',
                'detalle' => 'Está completa y nadie la calificó todavía.'],
        };

        // --- Documentos ---
        $documentos = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->with('tipoDocumento')
            ->get();

        $sinCalificar = $documentos->whereNull('Estado_Calificacion');
        $nombresSinCalificar = $sinCalificar
            ->map(fn ($d) => $d->tipoDocumento?->Nombre_Documento ?? 'Documento')
            ->values()
            ->all();

        $docs = match (true) {
            $proveedor->Fecha_Registro_Documentacion === null => [
                'estado' => 'incompleta', 'etiqueta' => 'Incompleta', 'pendientes' => [],
                'detalle' => $documentos->isEmpty()
                    ? 'El proveedor todavía no subió documentos.'
                    : 'El proveedor no registró su documentación; todavía puede cambiarla.',
            ],
            $documentos->contains('Estado_Calificacion', 'Rechazado') => [
                'estado' => 'rechazada', 'etiqueta' => 'Rechazada', 'pendientes' => $nombresSinCalificar,
                'detalle' => 'Tiene documentos rechazados; se espera que el proveedor los corrija.',
            ],
            $sinCalificar->isEmpty() => [
                'estado' => 'aprobada', 'etiqueta' => 'Aprobada', 'pendientes' => [], 'detalle' => null,
            ],
            default => [
                'estado' => 'pendiente', 'etiqueta' => 'Por calificar', 'pendientes' => $nombresSinCalificar,
                'detalle' => count($nombresSinCalificar) === 1
                    ? '1 documento sin revisar.'
                    : count($nombresSinCalificar).' documentos sin revisar.',
            ],
        };

        // --- Productos ---
        // Los recién aprobados por Compras, y aparte los que ya estaban
        // esperando a Calidad de antes: también son trabajo pendiente.
        $idsRecien = $aprobados->pluck('Id_Producto')->map(fn ($id) => (int) $id)->all();

        $otros = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Etapa_Aprobacion', Producto::ETAPA_CALIDAD)
            ->where('Estado_Calificacion', 'Pendiente')
            ->whereNotIn('Id_Producto', $idsRecien)
            ->orderBy('Nombre_Producto')
            ->pluck('Nombre_Producto')
            ->all();

        return [
            'ficha' => $ficha,
            'documentos' => $docs,
            'productos' => [
                'recien_aprobados' => $aprobados->pluck('Nombre_Producto')->values()->all(),
                'otros_pendientes' => $otros,
            ],
        ];
    }

    /**
     * Paso 3: Compras lo rechazó o lo eliminó. Al proveedor SIEMPRE con el
     * motivo: un rechazo sin explicación lo deja sin saber qué corregir, y
     * es justamente el reclamo que originó este cambio.
     */
    public function avisarAlProveedor(Producto $producto, string $observacion, bool $eliminado): void
    {
        $proveedor = $producto->proveedor;
        $correo = $proveedor->Correo_Calidad ?: $proveedor->Email;

        if (blank($correo)) {
            Log::warning('Aviso al proveedor: no tiene correo cargado.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'id_producto' => $producto->Id_Producto,
            ]);

            return;
        }

        $this->enviar(
            [$correo],
            new AvisoProductoMail(
                asunto: $eliminado
                    ? "Producto retirado del catálogo: {$producto->Nombre_Producto}"
                    : "Producto por corregir: {$producto->Nombre_Producto}",
                mensaje: $eliminado
                    ? 'Revisamos el producto que enviaste y <strong>no se incorporó al catálogo</strong>. '
                        .'Abajo está el motivo. Si corresponde, puedes volver a cargarlo corrigiendo lo indicado.'
                    : 'Revisamos el producto que enviaste y <strong>necesita correcciones</strong> antes de '
                        .'continuar. Abajo está el motivo; corrígelo y vuelve a enviarlo a aprobación.',
                nombreProveedor: $this->nombreDe($proveedor),
                nombreProducto: $producto->Nombre_Producto,
                observacion: $observacion,
                urlAccion: $eliminado ? null : $this->url('/productos'),
                textoAccion: 'Corregir el producto',
                esRechazo: true,
            ),
            contexto: ['paso' => 'proveedor', 'id_producto' => $producto->Id_Producto, 'eliminado' => $eliminado]
        );
    }

    /**
     * Correos de los usuarios ACTIVOS con ese rol en la empresa. Para
     * Compras se agregan además las copias fijas de configuración.
     *
     * @return array<int, string>
     */
    private function correosDelRol(int $idEmpresa, string $rol): array
    {
        $correos = Usuario::where('Activo', 1)
            ->whereHas('usuarioEmpresas', fn ($q) => $q
                ->where('Id_Empresa', $idEmpresa)
                ->where('Activo', true)
                ->whereHas('rol', fn ($r) => $r->where('Nombre_Rol', $rol)))
            ->pluck('Email')
            ->all();

        if ($rol === 'Compras') {
            $correos = array_merge($correos, config('portal.copias_aviso_compras', []));
        }

        // unique + filter: las jefaturas podrían tener además el rol en el
        // portal, y nadie quiere recibir el mismo aviso dos veces.
        return array_values(array_unique(array_filter($correos)));
    }

    /** @param array<int, string> $destinatarios */
    private function enviar(array $destinatarios, AvisoProductoMail|PendientesCalidadMail $correo, array $contexto = []): void
    {
        try {
            Mail::to($destinatarios)->queue($correo);
        } catch (\Throwable $e) {
            Log::error('No se pudo encolar el aviso de producto.', $contexto + [
                'destinatarios' => $destinatarios,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nombreDe($proveedor): string
    {
        return $proveedor->nombreVisible();
    }

    private function url(string $ruta): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$ruta;
    }
}
