<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $meta['title'] }} · {{ $meta['organization'] }}</title>
    <style>
        @page { margin: 28px 28px 42px 28px; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 8.5px; color: #1f2937; }
        .header { border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 10px; }
        .header table { width: 100%; border-collapse: collapse; }
        .org { font-size: 13px; font-weight: bold; }
        .logo { max-height: 38px; max-width: 160px; }
        .title { font-size: 15px; font-weight: bold; margin: 2px 0 0; }
        .muted { color: #6b7280; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .meta td.k { width: 120px; color: #6b7280; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 7.5px; word-break: break-all; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #1f2937; color: #fff; text-align: left; padding: 4px 3px; font-size: 7.5px; }
        table.data td { padding: 3px; border-bottom: 1px solid #e5e7eb; font-size: 7.5px; }
        table.data tr:nth-child(even) td { background: #f9fafb; }
        .num { text-align: right; }
        .totals { margin-top: 8px; width: 100%; border-collapse: collapse; }
        .totals td { padding: 3px 4px; border-top: 1px solid #1f2937; font-weight: bold; }
        .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 8px; margin-bottom: 10px; }
        .ok { color: #047857; font-weight: bold; }
        .bad { color: #b91c1c; font-weight: bold; }
        .note { margin-top: 8px; padding: 6px 8px; background: #fef3c7; border: 1px solid #f59e0b; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; font-size: 7px; color: #6b7280; }
        .footer .page:after { content: counter(page) " / " counter(pages); }
    </style>
</head>
<body>
<div class="footer">
    <table style="width:100%"><tr>
        <td>{{ $meta['organization'] }} · {{ $meta['title'] }} · ID {{ $meta['uuid'] }}</td>
        <td style="text-align:right">Página <span class="page"></span></td>
    </tr></table>
</div>

<div class="header">
    <table><tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" class="logo" alt="{{ $meta['organization'] }}">
            @else
                <div class="org">{{ $meta['organization'] }}</div>
            @endif
            <div class="title">{{ $meta['title'] }}</div>
            <div class="muted">{{ $definition['description'] }}</div>
        </td>
        <td style="text-align:right; vertical-align:top" class="muted">
            Periodo<br><strong style="color:#1f2937; font-size:10px">{{ $meta['period'] }}</strong>
        </td>
    </tr></table>
</div>

<table class="meta">
    <tr><td class="k">Empresa</td><td>{{ $meta['organization'] }}</td><td class="k">Generado</td><td>{{ $meta['generated_at'] }} ({{ $meta['timezone'] }})</td></tr>
    <tr><td class="k">Parámetros</td><td>{{ $meta['filters'] ?: 'Sin filtros adicionales' }}</td><td class="k">Responsable</td><td>{{ $meta['responsible'] }}</td></tr>
    <tr><td class="k">Registros</td><td>{{ number_format($meta['count']) }}</td><td class="k">Identificador</td><td class="mono">{{ $meta['uuid'] }}</td></tr>
    <tr><td class="k">SHA-256 de los datos</td><td colspan="3" class="mono">{{ $meta['data_sha256'] }}</td></tr>
    @if ($meta['observations'])
        <tr><td class="k">Observaciones</td><td colspan="3">{{ $meta['observations'] }}</td></tr>
    @endif
</table>

@if ($integrity)
    <div class="box">
        <strong>Verificación de integridad del registro electrónico</strong> <span class="muted">· {{ $integrity['verified_at'] }}</span>
        <table class="meta" style="margin:4px 0 0">
            @foreach ($integrity['chains'] as $chain)
                <tr>
                    <td class="k">{{ $chain['label'] }}</td>
                    <td>
                        <span class="{{ $chain['valid'] ? 'ok' : 'bad' }}">{{ $chain['valid'] ? 'Íntegra' : 'Con hallazgos' }}</span>
                        · {{ number_format($chain['checked']) }} registros · secuencia de cabeza {{ $chain['head_sequence'] }} · anclas verificadas {{ $chain['anchors_checked'] }}
                        <div class="mono">{{ $chain['head_hash'] }}</div>
                        @foreach (array_slice($chain['errors'], 0, 20) as $error)
                            <div class="bad">#{{ $error['sequence'] }}: {{ $error['error'] }}</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table>
        <div class="muted" style="margin-top:4px">
            Cada marcaje está encadenado con el hash del anterior (SHA-256) y las cabezas de cadena se anclan con firma HMAC fuera de la base de datos.
            Una alteración, inserción o eliminación rompe la cadena y aparece como hallazgo.
        </div>
    </div>
@endif

@if (count($rows) === 0)
    <p class="muted" style="padding: 20px 0; text-align:center">Sin registros para los parámetros seleccionados.</p>
@else
    <table class="data">
        <thead>
        <tr>
            @foreach ($columns as $column)
                <th class="{{ in_array($column['type'], ['number', 'minutes', 'percent'], true) ? 'num' : '' }}">{{ $column['label'] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                @foreach ($columns as $i => $column)
                    <td class="{{ in_array($column['type'], ['number', 'minutes', 'percent'], true) ? 'num' : '' }} {{ $column['type'] === 'hash' ? 'mono' : '' }}">{{ $column['type'] === 'hash' ? substr((string) $row[$i], 0, 16).'…' : $row[$i] }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if (count($meta['totals']) > 0)
    <table class="totals">
        <tr>
            @foreach ($meta['totals'] as $total)
                <td>{{ $total['label'] }}: {{ $total['value'] }}</td>
            @endforeach
        </tr>
    </table>
@endif

@if ($truncated)
    <div class="note">El PDF muestra los primeros {{ number_format($maxRows) }} de {{ number_format($meta['count']) }} registros. Los totales y la huella SHA-256 corresponden al conjunto completo; exporta en Excel o CSV para el detalle íntegro.</div>
@endif
</body>
</html>
