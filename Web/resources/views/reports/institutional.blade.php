<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $definition->title }} · {{ $header->legalName }}</title>
    @php
        $fs = $dense ? 6.4 : 7.4;
        $count = count($columns);
        $firstSum = null;
        foreach ($columns as $i => $c) { if ($c->sum || $c->ratio) { $firstSum = $i; break; } }
        $labelSpan = max(1, $firstSum ?? $count);
    @endphp
    <style>
        @page { margin: 92pt 24pt 34pt 24pt; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: {{ $fs }}pt; color: #1f2937; line-height: 1.25; }
        #page-header { position: fixed; top: -78pt; left: 0; right: 0; height: 62pt; }
        #page-header table { width: 100%; border-collapse: collapse; }
        #page-header td { vertical-align: middle; padding: 0; }
        .logo { height: 34pt; }
        .org { font-size: 9pt; font-weight: bold; color: #111827; }
        .doc-title { font-size: 12.5pt; font-weight: bold; color: {{ $primary }}; margin-top: 1pt; }
        .meta-right { text-align: right; font-size: 7pt; color: #4b5563; line-height: 1.45; }
        .meta-right strong { color: #111827; }
        .rule { height: 2.2pt; background: {{ $primary }}; margin-top: 5pt; }
        .rule-accent { height: 0.8pt; background: {{ $accent }}; margin-top: 1.2pt; }

        .info { width: 100%; border-collapse: collapse; margin: 0 0 8pt; border: 0.6pt solid #d6dedc; }
        .info td { padding: 3pt 5pt; vertical-align: top; font-size: 7.2pt; border-bottom: 0.4pt solid #e5ebea; word-wrap: break-word; }
        .info td.k { width: 13%; color: #4b5563; background: #f3f6f5; font-weight: bold; }
        .info td.v { width: 37%; }
        .desc { color: #4b5563; font-size: 7.4pt; margin: 0 0 6pt; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 6.4pt; word-wrap: break-word; }

        table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.data thead { display: table-header-group; }
        table.data th { background: {{ $primary }}; color: #fff; text-align: left; padding: 3.2pt 2.2pt; font-size: {{ $fs - 0.6 }}pt; font-weight: bold; vertical-align: bottom; border-right: 0.4pt solid #3d7a71; line-height: 1.15; }
        table.data th.num { text-align: right; padding-left: 4pt; }
        table.data td.num { padding-left: 4pt; }
        table.data td { padding: 2.4pt 2.2pt; border-bottom: 0.4pt solid #e3e8e7; vertical-align: top; }
        table.data td { word-wrap: break-word; }
        table.data tr { page-break-inside: avoid; }
        table.data tr.alt td { background: #f8faf9; }
        td.num { text-align: right; white-space: nowrap; }
        .c { text-align: center; }
        tr.g0 td { background: #e6efed; color: {{ $primary }}; font-weight: bold; padding: 4pt 4pt; border-top: 0.8pt solid {{ $primary }}; border-bottom: 0.6pt solid #c4d5d1; font-size: {{ $fs + 0.6 }}pt; }
        tr.g0 td .h { font-weight: normal; color: #374151; font-size: {{ $fs }}pt; }
        tr.g1 td { background: #f4f6f6; color: #374151; font-weight: bold; padding: 2.6pt 4pt; font-size: {{ $fs }}pt; }
        tr.sub td { background: #f7f1e6; font-weight: bold; border-top: 0.6pt solid {{ $accent }}; border-bottom: 0.6pt solid {{ $accent }}; color: #3b2f1a; }
        tr.sub0 td { background: #efe4cf; }
        tr.total td { background: {{ $primary }}; color: #fff; font-weight: bold; padding: 4pt 2.4pt; font-size: {{ $fs + 0.4 }}pt; }

        .box { border: 0.6pt solid #d6dedc; padding: 5pt 7pt; margin: 0 0 8pt; }
        .box-title { font-weight: bold; color: {{ $primary }}; font-size: 8pt; margin-bottom: 3pt; }
        .ok { color: #2f7d3a; font-weight: bold; }
        .bad { color: #c8322b; font-weight: bold; }
        .muted { color: #6b7280; }
        .totals { width: 100%; border-collapse: separate; border-spacing: 4pt 0; margin: 8pt -4pt 0; page-break-inside: avoid; }
        .totals td { border: 0.6pt solid #d6dedc; border-top: 2pt solid {{ $primary }}; padding: 4pt 5pt; vertical-align: top; }
        .totals .tl { font-size: 6.6pt; color: #4b5563; }
        .totals .tv { font-size: 10.5pt; font-weight: bold; color: #111827; margin-top: 1pt; }
        .note { margin-top: 8pt; padding: 5pt 7pt; background: #fdf6e7; border: 0.6pt solid {{ $accent }}; font-size: 7pt; }
        .empty { padding: 22pt 0; text-align: center; color: #6b7280; font-size: 9pt; border: 0.6pt dashed #cbd5d3; }
    </style>
</head>
<body>
<div id="page-header">
    <table>
        <tr>
            <td style="width: 24%">
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="Logotipo">
                @else
                    <div class="org">{{ $header->organizationName }}</div>
                @endif
            </td>
            <td style="width: 46%">
                <div class="org">{{ $header->legalName }}</div>
                <div class="doc-title">{{ $definition->title }}</div>
            </td>
            <td style="width: 30%" class="meta-right">
                Folio <strong>{{ $header->folio ?? 'Vista previa' }}</strong><br>
                Periodo <strong>{{ $header->period }}</strong><br>
                RFC <strong>{{ $header->rfc ?? 'Sin capturar' }}</strong>
            </td>
        </tr>
    </table>
    <div class="rule"></div>
    <div class="rule-accent"></div>
</div>

<p class="desc">{{ $definition->description }}</p>

<table class="info">
    <tr>
        <td class="k">Razón social</td><td class="v">{{ $header->legalName }}</td>
        <td class="k">RFC</td><td class="v">{{ $header->rfc ?? 'Sin capturar' }}</td>
    </tr>
    <tr>
        <td class="k">Centro de trabajo</td><td class="v">{{ $header->workCenter }}</td>
        <td class="k">Periodo</td><td class="v">{{ $header->period }}</td>
    </tr>
    <tr>
        <td class="k">Generado</td><td class="v">{{ $header->generatedAt }} ({{ $header->timezone }})</td>
        <td class="k">Generó</td><td class="v">{{ $header->generatedBy }}@if ($header->generatedByEmail) · {{ $header->generatedByEmail }}@endif</td>
    </tr>
    <tr>
        <td class="k">Folio</td><td class="v">{{ $header->folio ?? 'Vista previa' }}</td>
        <td class="k">Registros</td><td class="v">{{ number_format($dataset->rowCount()) }}</td>
    </tr>
    <tr>
        <td class="k">Filtros</td><td class="v">{{ $header->filters !== '' ? $header->filters : 'Sin filtros adicionales' }}</td>
        <td class="k">SHA-256 datos</td><td class="v mono">{{ $dataset->dataSha256() }}</td>
    </tr>
    @if ($header->observations)
        <tr><td class="k">Observaciones</td><td class="v" colspan="3">{{ $header->observations }}</td></tr>
    @endif
</table>

@if ($dataset->integrity)
    <div class="box">
        <div class="box-title">Verificación de integridad del registro electrónico · {{ $dataset->integrity['verified_at'] }}</div>
        <table class="info" style="margin:0">
            @foreach ($dataset->integrity['chains'] as $chain)
                <tr>
                    <td class="k" style="width:20%">{{ $chain['label'] }}</td>
                    <td>
                        <span class="{{ $chain['valid'] ? 'ok' : 'bad' }}">{{ $chain['valid'] ? 'Íntegra' : 'Con hallazgos' }}</span>
                        · {{ number_format($chain['checked']) }} registros · secuencia de cabeza {{ $chain['head_sequence'] }} · anclas verificadas {{ $chain['anchors_checked'] }}
                        <div class="mono">Hash de cabeza: {{ $chain['head_hash'] }}</div>
                        @foreach (array_slice($chain['errors'], 0, 20) as $error)
                            <div class="bad">#{{ $error['sequence'] }}: {{ $error['error'] }}</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table>
        <div class="muted" style="margin-top:3pt">Cada marcaje se encadena con el hash SHA-256 del anterior y las cabezas de cadena se anclan con firma HMAC fuera de la base de datos. Cualquier alteración, inserción o eliminación rompe la cadena y aparece como hallazgo.</div>
    </div>
@endif

@if ($dataset->rowCount() === 0)
    <div class="empty">Sin registros para los parámetros seleccionados.</div>
@else
    <table class="data">
        <colgroup>
            @foreach ($widths as $w)
                <col style="width: {{ $w }}%">
            @endforeach
        </colgroup>
        <thead>
        <tr>
            @foreach ($columns as $i => $column)
                <th class="{{ $column->isNumeric() ? 'num' : '' }}" style="width: {{ $widths[$i] }}%">{{ $column->label }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @php $alt = false; @endphp
        @foreach ($rows as $row)
            @if ($row['kind'] === 'group')
                @php $alt = false; @endphp
                <tr class="g{{ min(1, $row['level']) }}">
                    <td colspan="{{ $count }}">
                        {{ $row['label'] }}
                        @if (! empty($row['hoisted']))
                            <span class="h">&nbsp;·&nbsp;@foreach ($row['hoisted'] as $h){{ $h['label'] }}: {{ $h['value'] }}@if (! $loop->last) &nbsp;·&nbsp; @endif @endforeach</span>
                        @endif
                    </td>
                </tr>
            @elseif ($row['kind'] === 'subtotal')
                <tr class="sub {{ $row['level'] === 0 ? 'sub0' : '' }}">
                    <td colspan="{{ $labelSpan }}">{{ $row['label'] }} · {{ number_format($row['count']) }} {{ $row['count'] === 1 ? 'registro' : 'registros' }}</td>
                    @foreach ($columns as $i => $column)
                        @if ($i >= $labelSpan)
                            <td class="{{ $column->isNumeric() ? 'num' : '' }}">{{ $row['cells'][$i] ?? '' }}</td>
                        @endif
                    @endforeach
                </tr>
            @else
                <tr class="{{ $alt ? 'alt' : '' }}">
                    @foreach ($columns as $i => $column)
                        <td class="{{ $column->isNumeric() ? 'num' : '' }} {{ $column->type === 'hash' ? 'mono' : '' }} {{ in_array($column->type, ['time', 'date'], true) ? 'c' : '' }}">{{ $column->type === 'hash' && $row['cells'][$i] !== '' ? substr($row['cells'][$i], 0, 12).'…' : $row['cells'][$i] }}</td>
                    @endforeach
                </tr>
                @php $alt = ! $alt; @endphp
            @endif
        @endforeach
        @if ($totals !== [] && ! $truncated)
            <tr class="total">
                <td colspan="{{ $labelSpan }}">{{ $labelSpan > 1 ? 'TOTAL GENERAL · '.number_format($dataset->rowCount()).' registros' : 'TOTAL' }}</td>
                @foreach ($columns as $i => $column)
                    @if ($i >= $labelSpan)
                        <td class="{{ $column->isNumeric() ? 'num' : '' }}">{{ $totals[$i] ?? '' }}</td>
                    @endif
                @endforeach
            </tr>
        @endif
        </tbody>
    </table>
@endif

@if ($totalList !== [])
    @php $chunks = array_chunk($totalList, 6); @endphp
    @foreach ($chunks as $chunk)
        <table class="totals">
            <tr>
                @foreach ($chunk as $t)
                    <td style="width: {{ round(100 / 6, 2) }}%"><div class="tl">Total · {{ $t['label'] }}</div><div class="tv">{{ $t['value'] }}</div></td>
                @endforeach
                @for ($i = count($chunk); $i < 6; $i++)
                    <td style="border:0"></td>
                @endfor
            </tr>
        </table>
    @endforeach
@endif

@if ($truncated)
    <div class="note">El PDF muestra las primeras {{ number_format($maxRows) }} de {{ number_format($dataset->rowCount()) }} filas. Los totales y la huella SHA-256 corresponden al conjunto completo; exporta en Excel o CSV para el detalle íntegro.</div>
@endif
@foreach ($dataset->notes as $note)
    <div class="note">{{ $note }}</div>
@endforeach
</body>
</html>
