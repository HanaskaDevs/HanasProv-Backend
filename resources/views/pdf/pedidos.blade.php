<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
            color: #16231F;
        }

        h1 {
            font-size: 16px;
            color: #142831;
            margin-bottom: 2px;
        }

        .empresa {
            font-size: 11px;
            color: #274E61;
            margin-bottom: 20px;
        }

        .pedido {
            margin-bottom: 24px;
            page-break-inside: avoid;
        }

        .encabezado-pedido {
            background-color: #BDDEEB;
            padding: 8px 10px;
            font-weight: bold;
            color: #142831;
        }

        .fechas {
            font-size: 10px;
            color: #274E61;
            font-weight: normal;
            float: right;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }

        th,
        td {
            border-bottom: 1px solid #ddd;
            padding: 5px 8px;
            text-align: left;
            font-size: 11px;
        }

        th {
            background-color: #f5f7f7;
            color: #274E61;
        }
    </style>
</head>

<body>
    <h1>Hanaska — Pedidos de Compra</h1>
    <p class="empresa">{{ $nombreEmpresa }} · Generado el {{ $fechaGeneracion }}</p>

    @foreach ($pedidos as $pedido)
    <div class="pedido">
        <div class="encabezado-pedido">
            Pedido {{ $pedido->Nro_Pedido }}
            <span class="fechas">
                Fecha pedido: {{ optional($pedido->Fecha_Registro_BC)->format('Y-m-d') }} ·
                Recepción esperada: {{ optional($pedido->Fecha_Recepcion_Esperada)->format('Y-m-d') ?? 'No definida' }}
            </span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Descripción</th>
                    <th>Cantidad</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pedido->lineas as $linea)
                <tr>
                    <td>{{ $linea->Codigo_Producto }}</td>
                    <td>{{ $linea->Descripcion }}</td>
                    <td>{{ $linea->Cantidad }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach
</body>

</html>