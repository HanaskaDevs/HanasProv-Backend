<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Ficha_Productos\Mail\AvisoProductoMail;
use App\Modules\Ficha_Productos\Models\Producto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Los tres correos del circuito de aprobación de productos, en un solo
 * lugar (23-sep-2026):
 *
 *   1. el proveedor envía un producto -> avisa a COMPRAS
 *   2. Compras lo aprueba             -> avisa a CALIDAD
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

    /** Paso 2: Compras lo aprobó, ahora le toca a Calidad. */
    public function avisarACalidad(Producto $producto, Usuario $quienAprobo): void
    {
        $proveedor = $producto->proveedor;
        $destinatarios = $this->correosDelRol($proveedor->Id_Empresa, 'Calidad');

        if (empty($destinatarios)) {
            Log::warning('Aviso a Calidad: no hay a quién avisar.', [
                'id_empresa' => $proveedor->Id_Empresa,
                'id_producto' => $producto->Id_Producto,
            ]);

            return;
        }

        $this->enviar(
            $destinatarios,
            new AvisoProductoMail(
                asunto: "Producto aprobado por Compras, pendiente de Calidad: {$producto->Nombre_Producto}",
                mensaje: 'Compras revisó y aprobó un producto de <strong>'.e($this->nombreDe($proveedor))
                    .'</strong>. Queda pendiente la aprobación de Calidad para que el proveedor lo vea aprobado.',
                nombreProveedor: $this->nombreDe($proveedor),
                nombreProducto: $producto->Nombre_Producto,
                urlAccion: $this->url('/proveedores'),
                textoAccion: 'Calificar el producto',
            ),
            contexto: ['paso' => 'calidad', 'id_producto' => $producto->Id_Producto, 'aprobo' => $quienAprobo->Id_Usuario]
        );
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
    private function enviar(array $destinatarios, AvisoProductoMail $correo, array $contexto = []): void
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
        return $proveedor->Nombre_Comercial ?: ($proveedor->Razon_Social ?: 'Proveedor');
    }

    private function url(string $ruta): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$ruta;
    }
}
