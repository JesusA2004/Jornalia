/** Enlace a OpenStreetMap si las coordenadas son válidas. */
export function osmUrl(
    lat: string | number | null | undefined,
    lng: string | number | null | undefined,
    zoom = 17,
): string | null {
    const la = Number(lat);
    const lo = Number(lng);

    if (
        lat === null ||
        lat === undefined ||
        lat === '' ||
        lng === null ||
        lng === undefined ||
        lng === '' ||
        !Number.isFinite(la) ||
        !Number.isFinite(lo) ||
        Math.abs(la) > 90 ||
        Math.abs(lo) > 180
    ) {
        return null;
    }

    return `https://www.openstreetmap.org/?mlat=${la}&mlon=${lo}#map=${zoom}/${la}/${lo}`;
}

export function formatCoords(
    lat: string | number | null | undefined,
    lng: string | number | null | undefined,
): string | null {
    if (
        lat === null ||
        lat === undefined ||
        lng === null ||
        lng === undefined
    ) {
        return null;
    }

    return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`;
}
