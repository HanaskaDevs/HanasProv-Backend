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
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(20,40,49,0.08);">

        <tr>
          <td style="height:5px; background-color:#772125; line-height:5px; font-size:0;">&nbsp;</td>
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
          <td align="center" style="padding:8px 32px 4px 32px;">
            <p style="margin:0; font-size:19px; font-weight:bold; color:#142831;">Acceso suspendido</p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 16px 32px;">
            <p style="margin:0; font-size:13px; color:#6b7c82;">{{ $nombreProveedor }} · {{ $nombreEmpresa }}</p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 12px 0; font-size:14px; line-height:1.6; color:#142831;">
              Pasaron más de {{ $diasGracia }} días desde el vencimiento sin recibir la documentación actualizada,
              por lo que el acceso de este proveedor al portal quedó <strong>suspendido</strong>.
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:16px;">
                  <p style="margin:0 0 10px 0; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82;">
                    Documentación vencida
                  </p>
                  @foreach ($documentosVencidos as $documento)
                    <p style="margin:0 0 6px 0; font-size:14px; color:#142831;">• {{ $documento }}</p>
                  @endforeach
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:12px 32px 32px 32px;">
            <p style="margin:0; font-size:13px; line-height:1.6; color:#6b7c82;">
              Para reactivar el acceso, el proveedor debe contactarse con el administrador del portal y entregar
              la documentación actualizada.
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
