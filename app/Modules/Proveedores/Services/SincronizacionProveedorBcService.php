<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Models\ProveedorCuentaBancaria;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Registra en Business Central un proveedor que acaba de ser APROBADO en
 * el portal. Traduce los datos del portal a los 3 web services:
 *
 *   1. Ficha_proveedor_Excel      -> la ficha del proveedor
 *   2. Ficha_banco_proveedor_Excel-> su cuenta bancaria
 *   3. Dat_Adicionales_Proveedor  -> los datos tributarios del SRI
 *
 * El ORDEN importa: la cuenta bancaria necesita el Vendor_No que BC
 * asigna al crear la ficha, y recién con la cuenta creada se puede
 * marcar como preferida en la ficha (Preferred_Bank_Account_Code).
 *
 * Se postea en la compañía de la EMPRESA del proveedor
 * (Empresa.Codigo_Company_BC), no en una fija.
 */
class SincronizacionProveedorBcService
{
    public function __construct(private BusinessCentralClient $bc)
    {
    }

    /**
     * Punto de entrada desde la aprobación. NUNCA lanza: si BC falla, el
     * proveedor YA quedó aprobado en el portal y eso no se revierte por
     * un problema de integración -> se registra el error en el propio
     * Proveedor para poder reintentarlo después, y se sigue.
     *
     * @return bool true si quedó registrado en BC.
     */
    public function sincronizarSiCorresponde(Proveedor $proveedor): bool
    {
        if (! config('bc.habilitado')) {
            return false;
        }

        if (! $this->bc->estaConfigurado()) {
            Log::warning('BC: posteo habilitado pero faltan credenciales en el .env.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
            ]);

            return false;
        }

        // NO se corta si ya tiene Nro_Proveedor_BC: el número se guarda
        // apenas se crea la ficha, así que puede estar puesto y aun así
        // faltar el banco o los datos adicionales (si ese intento falló
        // a mitad). registrar() detecta que ya existe y solo completa lo
        // que falte; los 3 pasos son idempotentes.
        try {
            $nroBc = $this->registrar($proveedor);

            $proveedor->forceFill([
                'Nro_Proveedor_BC' => $nroBc,
                'Fecha_Posteo_BC' => now(),
                'Error_Posteo_BC' => null,
            ])->save();

            Log::info('BC: proveedor registrado.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'nro_proveedor_bc' => $nroBc,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Se guarda el error EN el proveedor (no solo en el log) para
            // que Sistemas vea desde el portal cuáles quedaron sin
            // registrar y por qué, en vez de tener que leer laravel.log.
            $proveedor->forceFill([
                'Error_Posteo_BC' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            Log::error('BC: no se pudo registrar el proveedor.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return string El "No" que BC asignó (o el que ya tenía). */
    protected function registrar(Proveedor $proveedor): string
    {
        $company = $this->companyDe($proveedor);
        $servicioFicha = config('bc.servicios.ficha_proveedor');

        // Ya se sabe su número (de un intento anterior que creó la ficha
        // pero no llegó a terminar) -> no hay nada que crear ni que
        // buscar, se va directo a completar los pasos que faltaron.
        if ($proveedor->Nro_Proveedor_BC) {
            $nroBc = $proveedor->Nro_Proveedor_BC;

            $this->sincronizarCuentaBancaria($company, $proveedor, $nroBc);
            $this->sincronizarDatosAdicionales($company, $proveedor, $nroBc);

            return $nroBc;
        }

        // ¿Ya existe en BC? Muchos proveedores del portal NO son nuevos:
        // ya estaban registrados en BC y el portal los está incorporando.
        // Sin esta búsqueda, BC rechazaba con "Identificación ya
        // registrada" y el proveedor quedaba trabado para siempre.
        $existente = $this->buscarPorIdentificacion($company, $proveedor->Ruc);

        if ($existente) {
            $nroBc = $existente['No'];

            // Por defecto solo se VINCULA, sin pisar la ficha que ya
            // existe en BC: ahí puede haber datos curados por Compras
            // (términos de pago, grupos contables) que el portal no
            // conoce. Con BC_ACTUALIZAR_EXISTENTES=true, además se
            // actualizan los campos que el portal sí gobierna.
            if (config('bc.actualizar_existentes')) {
                $this->actualizarIgnorandoReadOnly($company, $servicioFicha, "'{$nroBc}'", $this->payloadFicha($proveedor));
            }

            Log::info('BC: el proveedor ya existía, se vinculó al portal.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'nro_proveedor_bc' => $nroBc,
                'actualizado' => (bool) config('bc.actualizar_existentes'),
            ]);
        } else {
            // No se manda "No": BC lo asigna solo desde su serie de
            // numeración (PROV-00000XX).
            $creado = $this->bc->crear($company, $servicioFicha, $this->payloadFicha($proveedor));

            $nroBc = $creado['No'] ?? null;

            if (! $nroBc) {
                throw new RuntimeException('BC creó la ficha pero no devolvió el campo "No".');
            }

            // Se guarda YA, antes de los pasos que siguen. Si falla el
            // banco o los datos adicionales, el proveedor igual quedó
            // creado en BC: sin guardarlo acá se perdía el número y el
            // reintento trataba de crearlo de nuevo, chocando con
            // "Identificación ya registrada" (pasó en la primera prueba
            // real, 11-sep-2026).
            $proveedor->forceFill(['Nro_Proveedor_BC' => $nroBc])->save();
        }

        $this->sincronizarCuentaBancaria($company, $proveedor, $nroBc);
        $this->sincronizarDatosAdicionales($company, $proveedor, $nroBc);

        return $nroBc;
    }

    /** @return array<string, mixed>|null */
    protected function buscarPorIdentificacion(string $company, ?string $ruc): ?array
    {
        if (! $ruc) {
            return null;
        }

        $encontrados = $this->bc->consultar(
            $company,
            config('bc.servicios.ficha_proveedor'),
            "noIdentificacion eq '{$ruc}'"
        );

        return $encontrados[0] ?? null;
    }

    /**
     * Crea la cuenta bancaria solo si el proveedor no la tiene ya en BC
     * -> reintentar el posteo no debe duplicarle las cuentas.
     */
    protected function sincronizarCuentaBancaria(string $company, Proveedor $proveedor, string $nroBc): void
    {
        $cuenta = ProveedorCuentaBancaria::with('banco')
            ->where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->first();

        if (! $cuenta || ! $cuenta->banco) {
            return;
        }

        $servicioBanco = config('bc.servicios.banco_proveedor');

        $yaExiste = $this->bc->consultar(
            $company,
            $servicioBanco,
            "Vendor_No eq '{$nroBc}' and Code eq '{$cuenta->Nro_Cuenta}'"
        );

        if (empty($yaExiste)) {
            $this->bc->crear($company, $servicioBanco, [
                'Vendor_No' => $nroBc,
                // En el ejemplo de BC, Code y Bank_Account_No son el
                // mismo número de cuenta.
                'Code' => $cuenta->Nro_Cuenta,
                'Bank_Account_No' => $cuenta->Nro_Cuenta,
                'Name' => $cuenta->banco->Nombre_Banco,
                'Bank_Branch_No' => $cuenta->banco->Codigo_BC,
                'NOVAccountType' => $cuenta->Tipo_Cuenta,
                'Currency_Code' => config('bc.defaults.currency_code'),
            ]);
        }

        // Marcarla como preferida. Va DESPUÉS de crearla: BC no acepta
        // apuntar a una cuenta que todavía no existe.
        $this->bc->actualizar(
            $company,
            config('bc.servicios.ficha_proveedor'),
            "'{$nroBc}'",
            ['Preferred_Bank_Account_Code' => $cuenta->Nro_Cuenta]
        );
    }

    /**
     * BC CREA SOLO este registro al crear el proveedor (con
     * tipoIdentificacion/noIdentificacion ya cargados), y la página no
     * admite inserción -> un POST devuelve 405 "Entity does not support
     * insert". Por eso acá se ACTUALIZA, completando los campos que
     * aporta el portal.
     */
    protected function sincronizarDatosAdicionales(string $company, Proveedor $proveedor, string $nroBc): void
    {
        $this->actualizarIgnorandoReadOnly(
            $company,
            config('bc.servicios.datos_adicionales'),
            "'{$nroBc}'",
            $this->payloadDatosAdicionales($proveedor)
        );
    }

    /**
     * PATCH que se autocorrige ante campos de solo lectura.
     *
     * No tenemos el metadata de qué campos de estas páginas admiten
     * escritura, y varían entre entornos. BC igual lo dice en el error
     * ("Control 'X' is read-only") -> se quita ese campo y se reintenta,
     * en vez de fallar todo el posteo por un campo que BC deriva solo.
     *
     * El tope de intentos evita un bucle infinito si el mensaje cambia
     * de formato y no se logra extraer el campo.
     */
    protected function actualizarIgnorandoReadOnly(string $company, string $servicio, string $clave, array $datos): void
    {
        for ($intento = 0; $intento < 10; $intento++) {
            if (empty($datos)) {
                return;
            }

            try {
                $this->bc->actualizar($company, $servicio, $clave, $datos);

                return;
            } catch (\Throwable $e) {
                $campo = $this->campoDeSoloLectura($e->getMessage());

                if (! $campo || ! array_key_exists($campo, $datos)) {
                    throw $e;
                }

                unset($datos[$campo]);

                Log::info('BC: campo de solo lectura, se reintenta sin él.', [
                    'servicio' => $servicio,
                    'campo' => $campo,
                ]);
            }
        }
    }

    private function campoDeSoloLectura(string $mensaje): ?string
    {
        return preg_match("/Control '([^']+)' is read-only/i", $mensaje, $m) ? $m[1] : null;
    }

    protected function payloadFicha(Proveedor $proveedor): array
    {
        $defaults = config('bc.defaults');

        return [
            // BC tiene TODOS sus proveedores en mayúsculas (nombre,
            // dirección, ciudad). El portal los guarda en formato mixto
            // ("Quito", "Empresa de Prueba S.A.") -> se normaliza acá
            // para no ensuciar el listado de BC ni complicar las
            // búsquedas de quien trabaja allá.
            'Name' => $this->aMayusculas($proveedor->Razon_Social),
            'Search_Name' => $this->aMayusculas($proveedor->Razon_Social),
            'tipoIdentificacion' => $this->tipoIdentificacion($proveedor),
            'noIdentificacion' => $proveedor->Ruc,
            // Blanco en BC es UN ESPACIO, no cadena vacía, en este
            // servicio (así viene en los registros existentes) -> con ""
            // el enum lo rechaza.
            'AMNTipoProveedor' => $this->tipoProveedorBc($proveedor) ?: ' ',
            'LHCGrupoImpuesto' => $proveedor->Clase_Contribuyente,
            'Address' => $this->aMayusculas($proveedor->Direccion),
            'City' => $this->aMayusculas($proveedor->Ciudad),
            'Phone_No' => $proveedor->Telefono,
            'E_Mail' => $proveedor->Email,
            'Home_Page' => $proveedor->Pagina_Web ?? '',
            'Country_Region_Code' => $defaults['country_region_code'],
            'Language_Code' => $defaults['language_code'],
            'Format_Region' => $defaults['format_region'],
            'Gen_Bus_Posting_Group' => $defaults['gen_bus_posting_group'],
            'VAT_Bus_Posting_Group' => $defaults['vat_bus_posting_group'],
            'Vendor_Posting_Group' => $defaults['vendor_posting_group'],
            'Currency_Code' => $defaults['currency_code'],
            'Payment_Method_Code' => $defaults['payment_method_code'],
            // Payment_Terms_Code NO se manda: lo define Compras en BC
            // según la negociación con cada proveedor.
        ];
    }

    /** mb_strtoupper y no strtoupper: hay razones sociales con tildes/Ñ. */
    protected function aMayusculas(?string $texto): string
    {
        return $texto ? mb_strtoupper(trim($texto), 'UTF-8') : '';
    }

    protected function payloadDatosAdicionales(Proveedor $proveedor): array
    {
        // Solo los campos que BC deja escribir. Quedan FUERA a propósito:
        //   - 'No': es la clave, viaja en la URL del PATCH.
        //   - 'cuentaProveedor': BC lo marca read-only (lo deriva del
        //     proveedor) -> mandarlo devuelve 400.
        //   - 'razonSocial', 'tipoIdentificacion', 'noIdentificacion':
        //     también los deriva/autocompleta BC desde la ficha, que ya
        //     se creó con esos datos correctos.
        // Lo que sí aporta el portal son los datos tributarios del SRI.
        return [
            // Acá el blanco es cadena vacía (a diferencia de
            // AMNTipoProveedor en la ficha, que usa " ") -> así vienen
            // los registros existentes en BC.
            'tipoProveedor' => $this->tipoProveedorBc($proveedor) ?? '',
            'proveedorExtranjero' => false,
            'parte_relacionada' => false,
            'formaPago' => config('bc.defaults.forma_pago'),
            'TipoPago' => config('bc.defaults.tipo_pago'),
        ];
    }

    /**
     * El Tipo Proveedor de BC sale del Codigo_BC de la clase del portal.
     * Un proveedor puede tener varias clases; se toma la primera que
     * tenga mapeo. null = en BC va en blanco (caso Servicio).
     */
    protected function tipoProveedorBc(Proveedor $proveedor): ?string
    {
        return $proveedor->clases
            ->pluck('Codigo_BC')
            ->filter()
            ->first();
    }

    /**
     * R = RUC, C = Cédula. El portal valida el RUC en 13 dígitos, así que
     * en la práctica siempre es "R"; la lógica queda por si algún día se
     * admite cédula.
     */
    protected function tipoIdentificacion(Proveedor $proveedor): string
    {
        return strlen((string) $proveedor->Ruc) === 10 ? 'C' : 'R';
    }

    protected function companyDe(Proveedor $proveedor): string
    {
        $company = $proveedor->empresa?->Codigo_Company_BC;

        if (! $company) {
            // Falla explícito en vez de caer en una compañía por defecto:
            // registrar un proveedor en la empresa equivocada de BC es
            // mucho más caro de deshacer que no registrarlo.
            throw new RuntimeException(
                "La empresa #{$proveedor->Id_Empresa} no tiene Codigo_Company_BC configurado."
            );
        }

        return $company;
    }
}
