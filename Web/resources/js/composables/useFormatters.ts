import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import {
    browserTimeZone,
    formatCurrency,
    formatDate,
    formatDateTime,
    formatMinutes,
    formatNumber,
    formatPercent,
    formatRelative,
} from '@/lib/format';
import type { DateTimeFormat, MinutesFormat } from '@/lib/format';

type DateInput = string | number | Date | null | undefined;

export type UseFormattersReturn = {
    /** Zona por defecto: la de la organización activa o la del navegador. */
    timeZone: ComputedRef<string>;
    dateTime: (
        value: DateInput,
        format?: DateTimeFormat,
        timeZone?: string | null,
    ) => string;
    date: (
        value: string | Date | null | undefined,
        format?: Parameters<typeof formatDate>[1],
    ) => string;
    time: (value: DateInput, timeZone?: string | null) => string;
    relative: (value: DateInput) => string;
    minutes: (
        value: number | null | undefined,
        format?: MinutesFormat,
        signed?: boolean,
    ) => string;
    number: typeof formatNumber;
    percent: typeof formatPercent;
    currency: typeof formatCurrency;
};

export function useFormatters(): UseFormattersReturn {
    const page = usePage();
    const timeZone = computed(
        () => page.props.auth?.organization?.timezone ?? browserTimeZone(),
    );

    return {
        timeZone,
        dateTime: (value, format = 'datetime', tz) =>
            formatDateTime(value, { format, timeZone: tz ?? timeZone.value }),
        date: (value, format = 'date') => formatDate(value, format),
        time: (value, tz) =>
            formatDateTime(value, {
                format: 'time',
                timeZone: tz ?? timeZone.value,
            }),
        relative: (value) => formatRelative(value),
        minutes: (value, format = 'long', signed = false) =>
            formatMinutes(value, format, { signed }),
        number: formatNumber,
        percent: formatPercent,
        currency: formatCurrency,
    };
}
