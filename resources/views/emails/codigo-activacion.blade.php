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

        {{-- Barra de acento dorado arriba, mismo detalle de marca que usamos en el login/landing --}}
        <tr>
          <td style="height:5px; background-color:#E2DE3D; line-height:5px; font-size:0;">&nbsp;</td>
        </tr>

        {{-- Logo: incrustado directo en el correo (Content-ID), NO por URL.
             Si se referenciara con asset()/una URL absoluta, clientes de
             correo hosteados en la nube (Outlook Web, Gmail) necesitan
             que SUS servidores alcancen esa URL por internet -> si el
             servidor de la app solo es alcanzable en la red interna
             (IP privada), esa imagen nunca va a cargar para nadie,
             sin importar el dominio/host que se use. Incrustada, viaja
             como parte del propio correo, sin depender de ninguna red. --}}
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

        {{-- Contenido --}}
        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 16px 0; font-size:15px; color:#142831;">Hola,</p>

            @if($esReset)
              <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#142831;">
                Recibimos una solicitud para restablecer la contraseña de su cuenta en el Portal de Proveedores.
                Utilice el botón de abajo (o el código) para continuar con el proceso.
              </p>
            @else
              <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#142831;">
                Se creó una cuenta para usted en el Portal de Proveedores Hanaska. Utilice el botón de abajo
                (o el código) para activarla y elegir su contraseña.
              </p>
            @endif
          </td>
        </tr>

        {{-- Botón --}}
        <tr>
          <td align="center" style="padding:8px 32px 24px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="border-radius:8px; background-color:#142831;">
                  <a href="{{ $urlActivacion }}"
                     style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    {{ $esReset ? 'Restablecer mi contraseña' : 'Activar mi cuenta' }}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Código de respaldo --}}
        <tr>
          <td style="padding:0 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td align="center" style="padding:14px;">
                  <p style="margin:0 0 4px 0; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82;">
                    Código de activación
                  </p>
                  <p style="margin:0; font-size:20px; font-weight:bold; letter-spacing:2px; color:#142831; font-family:'Courier New', monospace;">
                    {{ $codigo }}
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 32px 8px 32px;">
            <p style="margin:0 0 12px 0; font-size:13px; line-height:1.6; color:#6b7c82;">
              Este código es válido por 20 minutos y de un solo uso. Si el botón no funciona, ingrese
              manualmente a la pantalla de activación con su correo y este código.
            </p>
          </td>
        </tr>

        {{-- Link alternativo, por si el botón no se puede hacer clic --}}
        <tr>
          <td style="padding:0 32px 32px 32px;">
            <p style="margin:0; font-size:11px; line-height:1.5; color:#9aa7ab; word-break:break-all;">
              Si tiene problemas con el botón, copie y pegue este enlace en su navegador:<br>
              <a href="{{ $urlActivacion }}" style="color:#274E61;">{{ $urlActivacion }}</a>
            </p>
          </td>
        </tr>

        {{-- Footer --}}
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