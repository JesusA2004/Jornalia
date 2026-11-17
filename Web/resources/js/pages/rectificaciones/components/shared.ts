import { router } from '@inertiajs/vue3';
import type { Tone } from '@/types';
import { cleanQuery, currentPath, currentQuery } from '@/lib/pagination';

/** Tono visual por estado de aprobación. */
export function approvalTone(status: string | null | undefined): Tone {
    switch (status) {
        case 'approved':
            return 'success';
        case 'rejected':
            return 'danger';
        case 'pending':
            return 'warning';
        default:
            return 'neutral';
    }
}

/** Navega conservando la query actual con cambios puntuales (tabs, semana, mes…). */
export function visitWith(
    changes: Record<string, string | number | boolean | null | undefined>,
    options: { resetPage?: boolean; only?: string[] } = {},
): void {
    const query: Record<string, unknown> = { ...currentQuery(), ...changes };
    if (options.resetPage !== false) {
        delete query.page;
    }
    router.get(currentPath(), cleanQuery(query), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: options.only,
    });
}

/** Fecha civil de hoy en una zona IANA (YYYY-MM-DD). */
export function todayIn(timezone: string | null | undefined): string {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone ?? undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date());
    } catch {
        return new Date().toISOString().slice(0, 10);
    }
}

/** Lista de fechas civiles inclusivas entre dos días. */
export function daysBetween(start: string, end: string): string[] {
    const out: string[] = [];
    const cursor = new Date(`${start}T12:00:00Z`);
    const last = new Date(`${end}T12:00:00Z`);
    while (cursor <= last) {
        out.push(cursor.toISOString().slice(0, 10));
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return out;
}

/** "a3f9…92be" para hashes largos. */
export function shortHash(hash: string | null | undefined, size = 8): string {
    if (!hash) {
        return '—';
    }

    return hash.length <= size * 2
        ? hash
        : `${hash.slice(0, size)}…${hash.slice(-4)}`;
}

/** Orden actual de la query (`sort` / `direction`) para <DataTable>. */
export function sortFromQuery(): {
    key: string;
    direction: 'asc' | 'desc';
} | null {
    const q = currentQuery();
    if (typeof q.sort !== 'string' || q.sort === '') {
        return null;
    }

    return { key: q.sort, direction: q.direction === 'asc' ? 'asc' : 'desc' };
}
