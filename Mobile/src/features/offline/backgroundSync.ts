import * as BackgroundTask from 'expo-background-task';
import * as TaskManager from 'expo-task-manager';

import { deviceIntegrity } from '@/features/attendance/integrity';
import { api, punchQueue } from '@/services/container';
import { getSession } from '@/storage/secure';

import { syncPunches } from './sync';

/**
 * Sincronización oportunista en segundo plano (WorkManager / BGTaskScheduler).
 * El SO decide cuándo ejecutarla (mínimo 15 min en Android); la app NO depende
 * de ella: también sincroniza al abrir, al reconectar y tras cada marcaje.
 * Los marcajes ya van firmados en la cola, por lo que aquí no se pide biometría.
 */
export const SYNC_TASK = 'mrlana-sync-punches';

TaskManager.defineTask(SYNC_TASK, async () => {
  try {
    const session = await getSession();
    if (!session) return BackgroundTask.BackgroundTaskResult.Success;
    await syncPunches({ queue: punchQueue, send: async (body) => api.sync.punches(body, await deviceIntegrity()) });
    return BackgroundTask.BackgroundTaskResult.Success;
  } catch {
    return BackgroundTask.BackgroundTaskResult.Failed;
  }
});

export async function registerBackgroundSync(): Promise<boolean> {
  try {
    const status = await BackgroundTask.getStatusAsync();
    if (status !== BackgroundTask.BackgroundTaskStatus.Available) return false;
    if (!(await TaskManager.isTaskRegisteredAsync(SYNC_TASK))) {
      await BackgroundTask.registerTaskAsync(SYNC_TASK, { minimumInterval: 15 });
    }
    return true;
  } catch {
    return false;
  }
}

export async function unregisterBackgroundSync(): Promise<void> {
  try {
    if (await TaskManager.isTaskRegisteredAsync(SYNC_TASK)) await BackgroundTask.unregisterTaskAsync(SYNC_TASK);
  } catch {
    // sin efecto
  }
}
