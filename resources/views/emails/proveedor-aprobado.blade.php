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
          <td style="height:5px; background-color:#E2DE3D; line-height:5px; font-size:0;">&nbsp;</td>
        </tr>

        <tr>
          <td align="center" style="padding:32px 32px 8px 32px;">
            <img src="{{ $message->embed(public_path('images/logo-hanaska.png')) }}" alt="Hanaska" width="140" style="display:block; max-width:140px; height:auto;">
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 8px 32px;">
            <p style="margin:0; font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#274E61; font-weight:bold;">
              Portal de Proveedores Hanaska
            </p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:8px 32px 16px 32px;">
            <p style="margin:0; font-size:34px; line-height:1;">🎉</p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 8px 32px;">
            <p style="margin:0; font-size:19px; font-weight:bold; color:#142831;">¡Felicidades, {{ $nombreProveedor }}!</p>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 8px 32px;">
            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#142831; text-align:center;">
              Su ficha, documentación y productos ya fueron revisados y aprobados. A partir de hoy es oficialmente
              <strong>proveedor aprobado de {{ $nombreEmpresa }}</strong> en el Portal de Proveedores.
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td align="center" style="padding:16px;">
                  <p style="margin:0; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82;">Empresa</p>
                  <p style="margin:4px 0 0 0; font-size:16px; font-weight:bold; color:#142831;">{{ $nombreEmpresa }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 32px 8px 32px;">
            <p style="margin:0 0 12px 0; font-size:13px; line-height:1.6; color:#6b7c82;">
              Ya puede ingresar al portal para seguir gestionando sus productos y pedidos con normalidad. Gracias por
              su paciencia durante el proceso de revisión.
            </p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:20px 32px; background-color:#f5f7f8; border-top:1px solid #e2e8ea;">
            <p style="margin:0; font-size:11px; color:#9aa7ab;">
              © {{ date('Y') }} Hanaska · Portal de Proveedores
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
