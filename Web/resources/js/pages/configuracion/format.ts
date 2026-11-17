/** Formato de fechas civiles (YYYY-MM-DD) sin desfase de zona horaria. */
export function civilDate(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    },
): string {
    if (!value) {
        return '—';
    }

    const [y, m, d] = value.slice(0, 10).split('-').map(Number);

    return new Intl.DateTimeFormat('es-MX', {
        ...options,
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(y, (m ?? 1) - 1, d ?? 1)));
}

export function hours(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) {
        return '—';
    }

    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

export function todayCivil(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}
