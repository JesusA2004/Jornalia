/**
 * Tipos de props de los módulos de administración y personal.
 * Cada módulo define sus tipos junto a sus páginas; aquí se agrupan por
 * espacio de nombres para evitar colisiones (p. ej. `ScheduleSegment`).
 *
 * ```ts
 * import type { Employees, Schedules } from '@/types/admin';
 * defineProps<Employees.EmployeeShowProps>();
 * ```
 */
export type * as Employees from '@/pages/colaboradores/types';
export type * as Schedules from '@/pages/horarios/types';
export type * as Devices from '@/pages/dispositivos/types';
export type * as Organization from '@/pages/organizacion/types';
export type * as Users from '@/pages/usuarios/types';
export type * as Settings from '@/pages/configuracion/types';
