<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\CalificacionCampoFicha;
use App\Modules\Proveedores\Models\ConfirmacionFicha;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Models\ProveedorCuentaBancaria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Maneja el guardado progresivo de la Ficha de Proveedor (auto-guardado por
 * sección, reanudable). Solo el propio usuario externo (Tipo_Usuario =
 * Proveedor) puede leer/editar SU propia ficha -> nunca se recibe un
 * Id_Proveedor desde el cliente, siempre se resuelve desde el usuario
 * autenticado + la empresa activa de su sesión (un mismo usuario puede
 * estar vinculado a Proveedores de más de una empresa, vía Usuario_Proveedor).
 *
 * 3 secciones en total por ahora: Información del Proveedor (33%),
 * Clase de Proveedor (33%), Categoría de Productos/Servicios (34%).
 *
 * ENVÍO A REVISIÓN Y POLÍTICAS (17-sep-2026): no existe un botón aparte de
 * "enviar"; la ficha entra a la cola de revisión del equipo en el momento
 * en que llega al 100% (así la leen DashboardSistemasService y la pantalla
 * de Proveedores). Por eso la aceptación de las Políticas de Hanaska se
 * exige EN EL GUARDADO QUE COMPLETA LA FICHA, sea cual sea la sección: si
 * ese guardado la dejaría al 100% y el proveedor todavía no aceptó, se
 * rechaza con 422 y no se escribe nada. Si aceptó, se guarda la sección y
 * en la misma transacción queda la fila en Confirmacion_Ficha.
 */
class FichaProveedorService
{
    /** Relaciones que el front necesita en cada respuesta de la ficha. */
    private const RELACIONES_FICHA = ['clases', 'categoriasProducto', 'calificacionesCampos', 'confirmacionFicha'];

    public function obtenerMiFicha(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        return $this->miProveedor($usuario, $idEmpresaActiva)->load([...self::RELACIONES_FICHA, 'estado']);
    }

    public function guardarSeccion1(Usuario $usuario, int $idEmpresaActiva, array $data, bool $aceptaPoliticas = false): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        // El Request ya exigió todos los campos obligatorios de la sección,
        // así que después de guardar la sección 1 queda completa: la ficha
        // se completa si las otras dos ya lo estaban.
        $completaraLaFicha = $proveedor->clases()->exists() && $proveedor->categoriasProducto()->exists();
        $this->exigirAceptacionSiCorresponde($proveedor, $completaraLaFicha, $aceptaPoliticas);

        DB::transaction(function () use ($proveedor, $data, $usuario, $completaraLaFicha) {
            $proveedor->forceFill([
                'Ruc' => $data['ruc'],
                'Clase_Contribuyente' => $data['clase_contribuyente'] ?? null,
                'Razon_Social' => $data['razon_social'],
                'Nombre_Comercial' => $data['nombre_comercial'] ?? null,
                'Email' => $data['email'],
                'Telefono' => $data['telefono'] ?? null,
                'Direccion' => $data['direccion'] ?? null,
                'Ciudad' => $data['ciudad'] ?? null,
                'Pagina_Web' => $data['pagina_web'] ?? null,
                'Latitud' => $data['latitud'] ?? null,
                'Longitud' => $data['longitud'] ?? null,
                'Representante_Legal' => $data['representante_legal'] ?? null,
                'Correo_Representante' => $data['correo_representante'] ?? null,
                'Telefono_Representante' => $data['telefono_representante'] ?? null,
                'Contacto_Venta' => $data['contacto_venta'] ?? null,
                'Correo_Venta' => $data['correo_venta'] ?? null,
                'Telefono_Contacto_Venta' => $data['telefono_contacto_venta'] ?? null,
                'Contacto_Calidad' => $data['contacto_calidad'] ?? null,
                'Correo_Calidad' => $data['correo_calidad'] ?? null,
                'Telefono_Contacto_Calidad' => $data['telefono_contacto_calidad'] ?? null,
                'Contacto_Contabilidad' => $data['contacto_contabilidad'] ?? null,
                'Correo_Contabilidad' => $data['correo_contabilidad'] ?? null,
                'Telefono_Contabilidad' => $data['telefono_contabilidad'] ?? null,
                'Modificado_Por' => $usuario->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ])->save();

            $this->recalcularProgreso($proveedor);
            $this->reabrirRevisionSiEstabaRechazada($proveedor, CalificacionProveedorService::CAMPOS_SECCION1);
            $this->registrarConfirmacionSiCorresponde($proveedor, $completaraLaFicha);
        });

        return $proveedor->fresh(self::RELACIONES_FICHA);
    }

    /**
     * Para un proveedor YA APROBADO: permite actualizar sus 4 bloques de
     * contacto (Representante Legal, Ventas, Calidad, Contabilidad)
     * libremente, cuando quiera, sin que eso dispare ninguna revisión
     * nueva -> el resto de la Ficha (Datos Generales, Clase, Categoría)
     * sigue bloqueado para edición directa a propósito, esos requieren
     * gestión aparte.
     */
    public function guardarContactosAprobado(Usuario $usuario, int $idEmpresaActiva, array $data): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ((int) $proveedor->Id_Estado_Proveedor !== EstadoProveedor::APROBADO) {
            throw new AccessDeniedHttpException('Esta acción es solo para proveedores ya aprobados.');
        }

        $proveedor->forceFill([
            'Representante_Legal' => $data['representante_legal'] ?? null,
            'Correo_Representante' => $data['correo_representante'] ?? null,
            'Telefono_Representante' => $data['telefono_representante'] ?? null,
            'Contacto_Venta' => $data['contacto_venta'] ?? null,
            'Correo_Venta' => $data['correo_venta'] ?? null,
            'Telefono_Contacto_Venta' => $data['telefono_contacto_venta'] ?? null,
            'Contacto_Calidad' => $data['contacto_calidad'] ?? null,
            'Correo_Calidad' => $data['correo_calidad'] ?? null,
            'Telefono_Contacto_Calidad' => $data['telefono_contacto_calidad'] ?? null,
            'Contacto_Contabilidad' => $data['contacto_contabilidad'] ?? null,
            'Correo_Contabilidad' => $data['correo_contabilidad'] ?? null,
            'Telefono_Contabilidad' => $data['telefono_contabilidad'] ?? null,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        return $proveedor->fresh(self::RELACIONES_FICHA);
    }

    public function guardarSeccion2(Usuario $usuario, int $idEmpresaActiva, array $idClases, bool $aceptaPoliticas = false): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        // El Request exige al menos una clase, así que esta sección queda
        // completa al guardar.
        $completaraLaFicha = self::seccion1EstaCompleta($proveedor) && $proveedor->categoriasProducto()->exists();
        $this->exigirAceptacionSiCorresponde($proveedor, $completaraLaFicha, $aceptaPoliticas);

        DB::transaction(function () use ($proveedor, $idClases, $usuario, $completaraLaFicha) {
            $proveedor->clases()->sync($idClases);

            $proveedor->forceFill([
                'Modificado_Por' => $usuario->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ])->save();

            $this->recalcularProgreso($proveedor);
            $this->reabrirRevisionSiEstabaRechazada($proveedor, [CalificacionProveedorService::CAMPO_CLASE]);
            $this->registrarConfirmacionSiCorresponde($proveedor, $completaraLaFicha);
        });

        return $proveedor->fresh(self::RELACIONES_FICHA);
    }

    public function guardarSeccion3(Usuario $usuario, int $idEmpresaActiva, array $idCategorias, bool $aceptaPoliticas = false): Proveedor
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        // Es la última sección del wizard, así que en la práctica este es
        // el guardado que "envía la ficha a revisión". El Request exige al
        // menos una categoría.
        $completaraLaFicha = self::seccion1EstaCompleta($proveedor) && $proveedor->clases()->exists();
        $this->exigirAceptacionSiCorresponde($proveedor, $completaraLaFicha, $aceptaPoliticas);

        DB::transaction(function () use ($proveedor, $idCategorias, $usuario, $completaraLaFicha) {
            $proveedor->categoriasProducto()->sync($idCategorias);

            $proveedor->forceFill([
                'Modificado_Por' => $usuario->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ])->save();

            $this->recalcularProgreso($proveedor);
            $this->reabrirRevisionSiEstabaRechazada($proveedor, [CalificacionProveedorService::CAMPO_CATEGORIA]);
            $this->registrarConfirmacionSiCorresponde($proveedor, $completaraLaFicha);
        });

        return $proveedor->fresh(self::RELACIONES_FICHA);
    }

    /** Mensaje que ve el proveedor si intenta enviar la ficha sin aceptar. */
    public const MENSAJE_POLITICAS_REQUERIDAS = 'Para enviar su ficha a revisión debe aceptar las Políticas de Hanaska.';

    /**
     * Corta el guardado con 422 si este es el que completa la ficha y el
     * proveedor no marcó la casilla de las políticas (y tampoco la había
     * aceptado en un envío anterior). Se llama ANTES de escribir nada, así
     * un rechazo no deja la sección guardada a medias ni la ficha al 100%
     * en la cola del equipo.
     *
     * La comprobación vive acá y no solo en el formulario a propósito: la
     * API es pública y el navegador solo ofrece comodidad (regla del
     * proyecto). Sin esto bastaría con omitir el campo para saltearse la
     * aceptación.
     */
    protected function exigirAceptacionSiCorresponde(Proveedor $proveedor, bool $completaraLaFicha, bool $aceptaPoliticas): void
    {
        if (! $completaraLaFicha || $aceptaPoliticas || $this->yaAceptoPoliticas($proveedor)) {
            return;
        }

        throw ValidationException::withMessages([
            'acepta_politicas' => [self::MENSAJE_POLITICAS_REQUERIDAS],
        ]);
    }

    /**
     * Deja la evidencia de la aceptación (fecha de hoy, sin hora) la primera
     * vez que la ficha llega al 100%. Idempotente: si ya existe la fila no
     * se toca, así una corrección posterior conserva la fecha original.
     *
     * Se llama DESPUÉS de exigirAceptacionSiCorresponde(): si se llegó acá
     * con $completaraLaFicha en true es porque el proveedor aceptó o ya
     * había aceptado antes.
     */
    protected function registrarConfirmacionSiCorresponde(Proveedor $proveedor, bool $completaraLaFicha): void
    {
        if (! $completaraLaFicha || $this->yaAceptoPoliticas($proveedor)) {
            return;
        }

        ConfirmacionFicha::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Fecha_Confirmacion' => today(),
        ]);
    }

    private function yaAceptoPoliticas(Proveedor $proveedor): bool
    {
        return ConfirmacionFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)->exists();
    }

    /**
     * Si alguno de los campos de ESTA sección estaba marcado "Rechazado"
     * por el admin, se borra esa calificación (vuelve a "Sin calificar")
     * -> como en la vista de corrección solo esos campos son editables,
     * el hecho de que se haya podido guardar esta sección implica que el
     * proveedor los tocó, así que corresponde que vuelvan a la cola de
     * revisión del admin. Los campos ya Aprobados de la misma sección NO
     * se tocan -> siguen aprobados (el proveedor no pudo haberlos
     * cambiado, estaban bloqueados en el formulario).
     */
    protected function reabrirRevisionSiEstabaRechazada(Proveedor $proveedor, array $camposDeEstaSeccion): void
    {
        CalificacionCampoFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->whereIn('Nombre_Campo', $camposDeEstaSeccion)
            ->where('Estado', 'Rechazado')
            ->delete();
    }

    /**
     * Cuenta bancaria declarada por el proveedor (la que acompaña al PDF
     * del certificado bancario). Devuelve null si todavía no la registró
     * -> el front muestra el botón "Registre sus datos" en vez del
     * resumen.
     */
    public function obtenerMiCuentaBancaria(Usuario $usuario, int $idEmpresaActiva): ?array
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $cuenta = ProveedorCuentaBancaria::with('banco')
            ->where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->first();

        return $cuenta ? $this->serializarCuenta($cuenta) : null;
    }

    /**
     * Guarda (o reemplaza) la cuenta bancaria del proveedor. Es upsert
     * por Id_Proveedor: la tabla tiene un unique ahí porque es LA cuenta
     * donde se le paga, no un historial.
     *
     * A propósito NO se recibe el Codigo_BC del banco desde el cliente:
     * llega el Id_Banco y el código se resuelve del catálogo al postear
     * a BC -> un cliente manipulado no puede inventar un código de
     * sucursal que BC no reconozca.
     */
    public function guardarMiCuentaBancaria(Usuario $usuario, int $idEmpresaActiva, array $data): array
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        $validados = validator($data, [
            'id_banco' => ['required', 'integer', Rule::exists('Banco', 'Id_Banco')->where('Activo', 1)],
            'tipo_cuenta' => ['required', Rule::in(ProveedorCuentaBancaria::TIPOS_CUENTA)],
            // Solo dígitos: los números de cuenta del país no llevan
            // guiones ni espacios, y BC los rechaza. Se recorta antes de
            // validar para no castigar un espacio pegado al copiar.
            'nro_cuenta' => ['required', 'string', 'max:30', 'regex:/^\d+$/'],
        ], [
            'nro_cuenta.regex' => 'El número de cuenta debe contener solo dígitos, sin guiones ni espacios.',
        ])->validate();

        $ahora = now();

        $cuenta = ProveedorCuentaBancaria::updateOrCreate(
            ['Id_Proveedor' => $proveedor->Id_Proveedor],
            [
                'Id_Banco' => $validados['id_banco'],
                'Tipo_Cuenta' => $validados['tipo_cuenta'],
                'Nro_Cuenta' => trim($validados['nro_cuenta']),
                'Registrado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => $ahora,
                'Fecha_Modificacion' => $ahora,
            ]
        );

        return $this->serializarCuenta($cuenta->load('banco'));
    }

    private function serializarCuenta(ProveedorCuentaBancaria $cuenta): array
    {
        return [
            'id_banco' => $cuenta->Id_Banco,
            // Se devuelve el NOMBRE, nunca el Codigo_BC -> ese es interno
            // de la integración con BC.
            'nombre_banco' => $cuenta->banco?->Nombre_Banco,
            'tipo_cuenta' => $cuenta->Tipo_Cuenta,
            'nro_cuenta' => $cuenta->Nro_Cuenta,
            'fecha_modificacion' => $cuenta->Fecha_Modificacion?->format('Y-m-d H:i'),
        ];
    }

    /**
     * Resuelve el Proveedor del usuario autenticado QUE PERTENECE A LA
     * EMPRESA ACTIVA de su sesión. Un mismo usuario externo puede estar
     * vinculado (vía Usuario_Proveedor) a Proveedores de distintas
     * empresas -> nunca alcanza con tomar "el primero", hay que filtrar
     * por la empresa con la que está trabajando en este momento.
     */
    protected function miProveedor(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) tienen Ficha de Proveedor.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresaActiva)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene una Ficha de Proveedor asociada a la empresa activa.');
        }

        return $proveedor;
    }

    /**
     * Recalcula Seccion_Actual (próxima sección incompleta) y
     * Porcentaje_Completado_Ficha según los datos realmente guardados ->
     * es tolerante a que se llenen fuera de orden y a reanudar en
     * cualquier momento.
     *
     * Solo 3 secciones en total por ahora (Información, Clase, Categoría),
     * ponderadas 33% + 33% + 34% para sumar exactamente 100%.
     */
    /**
     * Columnas que la sección 1 (Datos Generales) considera obligatorias.
     *
     * TIENE QUE ESPEJAR GuardarSeccion1Request: si el formulario exige un
     * campo y acá no está, la ficha se daría por completa sin él.
     *
     * Antes esta comprobación miraba SOLO Ruc y Razon_Social, y funcionaba
     * por accidente: esos dos campos únicamente podían llegar desde este
     * mismo formulario, que ya exigía todo el resto. Desde que la activación
     * de la cuenta pide RUC y razón social por adelantado (1-sep-2026), esos
     * dos campos existen desde el minuto cero -> la sección 1 se marcaba
     * completa al instante y, con la clase y la categoría elegidas, la ficha
     * saltaba a 100% y quedaba BLOQUEADA "pendiente de revisión" sin que el
     * proveedor hubiera cargado su dirección, sus teléfonos ni sus
     * contactos. Reportado el 2-sep-2026.
     */
    private const CAMPOS_OBLIGATORIOS_SECCION_1 = [
        'Ruc', 'Clase_Contribuyente', 'Razon_Social', 'Nombre_Comercial',
        'Email', 'Telefono', 'Direccion', 'Ciudad', 'Latitud', 'Longitud',
        'Representante_Legal', 'Correo_Representante', 'Telefono_Representante',
        'Contacto_Venta', 'Correo_Venta', 'Telefono_Contacto_Venta',
        'Contacto_Calidad', 'Correo_Calidad', 'Telefono_Contacto_Calidad',
        'Contacto_Contabilidad', 'Correo_Contabilidad', 'Telefono_Contabilidad',
    ];

    /** ¿Están cargados TODOS los datos que la sección 1 exige? */
    public static function seccion1EstaCompleta(Proveedor $proveedor): bool
    {
        foreach (self::CAMPOS_OBLIGATORIOS_SECCION_1 as $campo) {
            if (blank($proveedor->{$campo})) {
                return false;
            }
        }

        return true;
    }

    protected function recalcularProgreso(Proveedor $proveedor): void
    {
        $proveedor->refresh();

        $seccion1Completa = self::seccion1EstaCompleta($proveedor);
        $seccion2Completa = $proveedor->clases()->exists();
        $seccion3Completa = $proveedor->categoriasProducto()->exists();

        $porcentaje = 0;
        $porcentaje += $seccion1Completa ? 33 : 0;
        $porcentaje += $seccion2Completa ? 33 : 0;
        $porcentaje += $seccion3Completa ? 34 : 0;

        $siguienteSeccion = match (true) {
            ! $seccion1Completa => 1,
            ! $seccion2Completa => 2,
            ! $seccion3Completa => 3,
            default => 3,
        };

        $proveedor->forceFill([
            'Porcentaje_Completado_Ficha' => $porcentaje,
            'Seccion_Actual' => $siguienteSeccion,
        ])->save();
    }
}