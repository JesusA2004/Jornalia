import { setWorkerUrl } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

/**
 * MapLibre 6 construye la URL de su Web Worker en tiempo de ejecución
 * (`new URL('./maplibre-gl-worker.mjs', import.meta.url)`), patrón que Vite no
 * sigue: en el build el archivo no existe y ninguna fuente GeoJSON se procesa.
 * Emitimos el worker como recurso con hash y se lo indicamos a MapLibre.
 *
 * Módulo de efecto secundario: impórtalo antes de crear cualquier mapa.
 */
setWorkerUrl(workerUrl);
