import type { ChartConfig } from '@/components/ui/chart';
import type { ChartSeries } from '@/types';

/** Colores categóricos del sistema (tokens --chart-1…6). */
export const CHART_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
] as const;

export function seriesColor(series: ChartSeries, index: number): string {
    return series.color ?? CHART_COLORS[index % CHART_COLORS.length];
}

/** Convierte series a `ChartConfig` de shadcn-vue. */
export function toChartConfig(series: ChartSeries[]): ChartConfig {
    return Object.fromEntries(
        series.map((item, index) => [
            item.key,
            { label: item.label, color: seriesColor(item, index) },
        ]),
    );
}

const escapeMap: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

export function escapeHtml(value: string | number | null | undefined): string {
    return (value === null || value === undefined ? '' : `${value}`).replace(
        /[&<>"']/g,
        (char) => escapeMap[char],
    );
}

/**
 * HTML del tooltip (unovis recibe una cadena). Mismo aspecto que
 * ChartTooltipContent de shadcn-vue, con valores formateados.
 */
export function tooltipHtml(
    title: string | null,
    rows: Array<{ label: string; value: string; color: string }>,
): string {
    const header = title
        ? `<div class="mb-1.5 font-medium text-foreground first-letter:uppercase">${escapeHtml(title)}</div>`
        : '';

    const body = rows
        .map(
            (row) =>
                `<div class="flex items-center gap-2 py-0.5">` +
                `<span class="size-2.5 shrink-0 rounded-[3px]" style="background:${escapeHtml(row.color)}"></span>` +
                `<span class="text-muted-foreground">${escapeHtml(row.label)}</span>` +
                `<span class="ml-auto pl-4 font-medium tabular-nums text-foreground">${escapeHtml(row.value)}</span>` +
                `</div>`,
        )
        .join('');

    return `<div class="min-w-[9rem] rounded-lg border border-border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-elev-2">${header}${body}</div>`;
}
