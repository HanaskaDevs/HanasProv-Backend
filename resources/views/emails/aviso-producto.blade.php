{{--
  Plantilla ÚNICA para los tres avisos del circuito de aprobación de
  productos (ver AvisoProductosService):

    1. el proveedor envió un producto  -> a Compras
    2. Compras lo aprobó               -> a Calidad
    3. Compras lo rechazó o eliminó    -> al proveedor

  Una sola vista y no tres casi idénticas: lo que cambia entre los avisos es
  el texto y si hay observación, no la estructura. Tres copias del mismo
  HTML de correo se separan en cuanto alguien retoca una.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portal de Proveedores Hanaska</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f4; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f4; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(20,40,49,0.08);">

        <tr>
          <td style="height:5px; background-color:{{ $esRechazo ? '#772125' : '#E2DE3D' }}; line-height:5px; font-size:0;">&nbsp;</td>
        </tr>

        <tr>
          <td align="center" style="padding:32px 32px 8px 32px;">
            <img src="{{ $message->embed(public_path('images/logo-hanaska.png')) }}" alt="Hanaska" width="140" style="display:block; max-width:140px; height:auto;">
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 24px 32px;">
            <p style="margin:0; font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#274E61; font-weight:bold;">
              Portal de Proveedores Hanaska
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 16px 0; font-size:15px; color:#142831;">Hola,</p>
            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#142831;">{!! $mensaje !!}</p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:14px 16px;">
                  <p style="margin:0 0 4px 0; font-size:11px; letter-spacing:.5px; text-transform:uppercase; color:#274E61;">Proveedor</p>
                  <p style="margin:0 0 12px 0; font-size:15px; font-weight:bold; color:#142831;">{{ $nombreProveedor }}</p>

                  <p style="margin:0 0 4px 0; font-size:11px; letter-spacing:.5px; text-transform:uppercase; color:#274E61;">Producto</p>
                  <p style="margin:0; font-size:15px; font-weight:bold; color:#142831;">{{ $nombreProducto }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        @if ($observacion)
        <tr>
          <td style="padding:12px 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fbeded; border-radius:8px; border:1px solid #f0d4d4;">
              <tr>
                <td style="padding:14px 16px;">
                  <p style="margin:0 0 4px 0; font-size:11px; letter-spacing:.5px; text-transform:uppercase; color:#772125;">Motivo</p>
                  <p style="margin:0; font-size:14px; line-height:1.55; color:#142831;">{{ $observacion }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        @if ($urlAccion)
        <tr>
          <td align="center" style="padding:24px 32px 8px 32px;">
            {{-- Tabla y no un <a> suelto: Outlook ignora el padding de los
                 enlaces y el botón sale como texto plano. --}}
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="background-color:#142831; border-radius:8px;">
                  <a href="{{ $urlAccion }}" style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none;">
                    {{ $textoAccion }}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        <tr>
          <td style="padding:24px 32px 32px 32px;">
            <p style="margin:0; font-size:12px; line-height:1.6; color:#7c8a93;">
              Este es un aviso automático del Portal de Proveedores. No respondas a este correo.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
