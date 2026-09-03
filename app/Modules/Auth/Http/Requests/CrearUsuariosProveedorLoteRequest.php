<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Carga masiva de usuarios externos (Proveedores) desde un Excel.
 *
 * El Excel se lee en el NAVEGADOR (SheetJS ya estaba en el frontend, se usa
 * igual en el Catálogo de Productos), así que lo que llega acá es JSON: una
 * fila por proveedor. Eso NO convierte al navegador en la validación: la
 * API es pública, así que cada fila se revalida entera del lado del
 * servidor y los nombres de empresa se resuelven acá contra la tabla
 * Empresa; nunca se acepta un Id_Empresa elegido por el cliente.
 *
 * POR QUÉ ESTAS REGLAS SON TAN FLOJAS (a propósito):
 *
 * Acá solo se comprueba la FORMA del payload (que sea un arreglo de filas
 * con los campos presentes), no el CONTENIDO de cada fila. El formato del
 * correo, su largo y las empresas se validan fila por fila dentro de
 * UsuarioService::crearUsuariosProveedorEnLote.
 *
 * El motivo es concreto: con `filas.*.email => email` aquí, un solo correo
 * mal escrito en la fila 57 devuelve un 422 y NO se procesa ninguna de las
 * otras 79 filas buenas. En una carga masiva eso es inútil: quien sube el
 * archivo necesita que entren las que están bien y un reporte de las que
 * no. Los topes que sí viven acá (max:1000, max:100) son solo cordura para
 * que nadie mande un payload absurdo, no reglas de negocio.
 */
class CrearUsuariosProveedorLoteRequest extends FormRequest
{
    /**
     * Tope de filas por carga. No es un capricho: sin techo, una sola
     * petición puede pedirle al servidor decenas de miles de INSERT y de
     * correos encolados. Con 500 alcanza de sobra para el caso real
     * (decenas de proveedores) y un archivo mayor se parte en dos.
     *
     * Si se cambia, hay que cambiar también MAX_FILAS_CARGA_MASIVA en
     * src/modules/usuarios/api/usuariosApi.ts del frontend.
     */
    public const MAX_FILAS = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filas' => ['required', 'array', 'min:1', 'max:' . self::MAX_FILAS],

            // Presente y texto: el formato y el largo real (150) se revisan
            // por fila en el Service. Ver el bloque de arriba.
            'filas.*.email' => ['present', 'string', 'max:1000'],

            // Puede venir una entrada por empresa o una sola cadena con
            // varias separadas por ; | o salto de línea: el Service parte
            // ambos casos. `present` y no `required` para que una fila sin
            // empresas sea un error DE ESA FILA y no un 422 del archivo.
            'filas.*.empresas' => ['present', 'array'],
            'filas.*.empresas.*' => ['string', 'max:500'],

            // Referencia del archivo de Compras. NO se guarda en la base:
            // la tabla Proveedor no tiene esa columna y el código real vive
            // en Business Central, resuelto por RUC (ver
            // HorarioEntregaService::resolverCodigosBc). Viaja solo para
            // devolverlo en el reporte y poder cuadrar el Excel contra el
            // resultado fila por fila.
            'filas.*.codigo_proveedor' => ['nullable', 'string', 'max:100'],

            // Fila real de la hoja de cálculo, para que el reporte diga
            // cuál corregir. Si no llega, el Service usa el índice.
            'filas.*.numero_fila' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'filas.required' => 'El archivo no tiene ninguna fila para procesar.',
            'filas.max' => 'El archivo tiene demasiadas filas. El máximo por carga es ' . self::MAX_FILAS . '.',
        ];
    }
}
