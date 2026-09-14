{{--
  Correo que recibe el Delegado de Protección de Datos con una solicitud de
  ejercicio de derechos.

  Es un correo INTERNO de trabajo, no una comunicación al proveedor, así que
  prioriza que los datos se puedan leer y copiar de un vistazo: la LOPDP da
  15 días para responder y quien lo atienda necesita el nombre, la cédula y
  el correo a mano, no dentro de un párrafo.

  El estilo con tablas y estilos en línea es el mismo del resto de los
  correos del portal: los clientes de correo ignoran el CSS externo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Solicitud de derechos LOPDP</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f4; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f4; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(20,40,49,0.08);">

        <tr>
          <td style="height:5px; background-color:#274E61; line-height:5px; font-size:0;">&nbsp;</td>
        </tr>

        <tr>
          <td style="padding:28px 32px 4px 32px;">
            <p style="margin:0; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; color:#274E61; font-weight:bold;">
              Portal de Proveedores Hanaska
            </p>
            <h1 style="margin:8px 0 0 0; font-size:19px; color:#142831;">
              Solicitud de ejercicio de derechos
            </h1>
            <p style="margin:6px 0 0 0; font-size:13px; color:#5A6875;">
              Recibida el {{ $fecha }}
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:20px 32px 4px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px; color:#142831;">
              <tr>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec; color:#5A6875; width:38%;">Derecho solicitado</td>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec; font-weight:bold;">{{ $derechoEtiqueta }}</td>
              </tr>
              <tr>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec; color:#5A6875;">Nombre y apellidos</td>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec;">{{ $nombreCompleto }}</td>
              </tr>
              <tr>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec; color:#5A6875;">Cédula</td>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec;">{{ $cedula }}</td>
              </tr>
              <tr>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec; color:#5A6875;">Correo electrónico</td>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec;">
                  <a href="mailto:{{ $email }}" style="color:#274E61;">{{ $email }}</a>
                </td>
              </tr>
              <tr>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec; color:#5A6875;">Celular</td>
                <td style="padding:9px 0; border-bottom:1px solid #e3e9ec;">{{ $celular }}</td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 32px 0 32px;">
            <p style="margin:0 0 6px 0; font-size:12px; letter-spacing:0.5px; text-transform:uppercase; color:#5A6875; font-weight:bold;">
              Detalle de la solicitud
            </p>
            <div style="background-color:#f4f7f8; border-left:3px solid #BDDEEB; padding:12px 14px; font-size:14px; line-height:1.6; color:#142831; white-space:pre-wrap;">{{ $detalle }}</div>
          </td>
        </tr>

        <tr>
          <td style="padding:18px 32px 0 32px;">
            <div style="background-color:#fdf6e3; border:1px solid #e8d9a0; border-radius:8px; padding:12px 14px;">
              <p style="margin:0; font-size:13px; line-height:1.6; color:#5c4a12;">
                <strong>Plazo:</strong> la LOPDP obliga a responder dentro de los 15 días
                siguientes a la recepción. Si la solicitud procede, debe ejecutarse dentro de los 15
                días posteriores a comunicar la respuesta.
              </p>
              <p style="margin:8px 0 0 0; font-size:13px; line-height:1.6; color:#5c4a12;">
                <strong>Antes de ejecutar:</strong> pedir copia del documento de identidad si no
                viene adjunta. Sin acreditar al titular no se puede atender.
              </p>
            </div>
          </td>
        </tr>

        <tr>
          <td style="padding:18px 32px 26px 32px;">
            <p style="margin:0; font-size:13px; color:#5A6875; line-height:1.6;">
              El titular declaró que la información entregada es exacta, precisa, clara y verdadera.
              Puedes responderle directamente: este correo tiene configurada su dirección como
              destinatario de respuesta.
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:14px 32px; background-color:#f4f7f8; border-top:1px solid #e3e9ec;">
            <p style="margin:0; font-size:11px; color:#8b98a1; line-height:1.6;">
              Enviado automáticamente desde el formulario de atención de derechos del Portal de
              Proveedores{{ $ipOrigen ? ' · IP de origen: '.$ipOrigen : '' }}
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
