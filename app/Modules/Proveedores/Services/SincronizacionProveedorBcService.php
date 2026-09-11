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

        // Ya registrado -> no se vuelve a crear. Sin esta guarda, una
        // recalificación o el comando de reconciliación duplicarían el
        // proveedor en BC.
        if ($proveedor->Nro_Proveedor_BC) {
            return true;
        }

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

    /** @return string El "No" que BC asignó al proveedor. */
    protected function registrar(Proveedor $proveedor): string
    {
        $company = $this->companyDe($proveedor);

        // 1. Ficha del proveedor. No se manda "No": BC lo asigna solo
        //    desde su serie de numeración (PROV-00000XX).
        $creado = $this->bc->crear($company, config('bc.servicios.ficha_proveedor'), $this->payloadFicha($proveedor));

        $nroBc = $creado['No'] ?? null;

        if (! $nroBc) {
            throw new RuntimeException('BC creó la ficha pero no devolvió el campo "No".');
        }

        // 2. Cuenta bancaria (si el proveedor la declaró).
        $cuenta = ProveedorCuentaBancaria::with('banco')
            ->where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->first();

        if ($cuenta && $cuenta->banco) {
            $this->bc->crear($company, config('bc.servicios.banco_proveedor'), [
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

            // 3. Marcarla como cuenta preferida. Va DESPUÉS de crearla:
            //    BC no acepta apuntar a una cuenta que todavía no existe.
            $this->bc->actualizar(
                $company,
                config('bc.servicios.ficha_proveedor'),
                "'{$nroBc}'",
                ['Preferred_Bank_Account_Code' => $cuenta->Nro_Cuenta]
            );
        }

        // 4. Datos adicionales (SRI).
        $this->bc->crear($company, config('bc.servicios.datos_adicionales'), $this->payloadDatosAdicionales($proveedor, $nroBc));

        return $nroBc;
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

    protected function payloadDatosAdicionales(Proveedor $proveedor, string $nroBc): array
    {
        return [
            'No' => $nroBc,
            'cuentaProveedor' => $nroBc,
            'razonSocial' => $this->aMayusculas($proveedor->Razon_Social),
            'tipoIdentificacion' => $this->tipoIdentificacion($proveedor),
            'noIdentificacion' => $proveedor->Ruc,
            // Acá el blanco SÍ es cadena vacía (a diferencia de
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
