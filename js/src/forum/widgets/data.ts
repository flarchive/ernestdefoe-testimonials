import app from 'flarum/forum/app';
import type Testimonial from '../Testimonial';
import { api, onChange } from '../util';

export interface Summary {
  count: number;
  rated: number;
  average: number | null;
  spread: Record<string, number>;
  aspects: Array<{ id: number; name: string; average: number; count: number }>;
  subjects?: Array<{ type: string; id: number | null; label: string | null; count: number }>;
}

interface Entry<T> {
  value: T | null;
  busy: boolean;
  at: number;
}

/*
 * One answer per query, shared by every widget that asks it: a carousel in Bespoke's hero and
 * a wall in Page Builder with the same settings cost one request. Module level, because Bespoke
 * re-creates its widgets on every redraw (a fetch in oninit would loop).
 */
const lists = new Map<string, Entry<Testimonial[]>>();
const summaries = new Map<string, Entry<Summary>>();
const STALE = 5 * 60_000;

function get<T>(map: Map<string, Entry<T>>, key: string, load: () => Promise<T>, empty: T): T | null {
  let entry = map.get(key);
  if (!entry) map.set(key, (entry = { value: null, busy: false, at: 0 }));
  if (!entry.busy && (!entry.at || Date.now() - entry.at > STALE)) {
    const e = entry;
    e.busy = true;
    load()
      .then((value) => (e.value = value))
      // Keep what was shown; empty only if there never was anything.
      .catch(() => (e.value ??= empty))
      .finally(() => {
        e.busy = false;
        e.at = Date.now();
        m.redraw();
      });
  }
  return entry.value;
}

/** Live testimonials for these filters (`filter[...]` keys without the prefix), or null while loading. */
export function list(filters: Record<string, string | number>, limit: number): Testimonial[] | null {
  const params: Record<string, any> = { filter: filters, page: { limit } };
  return get(lists, JSON.stringify(params), () => app.store.find<Testimonial[]>('testimonials', params).then((rows) => [...(rows as any)]), []);
}

export function summary(params: Record<string, string | number>): Summary | null {
  return get(
    summaries,
    JSON.stringify(params),
    () =>
      app
        .request<{ meta: Summary }>({ method: 'GET', url: api('testimonials/summary'), params: { filter: params }, errorHandler: () => {} } as any)
        .then((doc) => doc.meta),
    { count: 0, rated: 0, average: null, spread: {}, aspects: [] }
  );
}

// A save, an approval or a take-down: everything is asked again the next time it's drawn.
onChange(() => {
  lists.forEach((entry) => (entry.at = 0));
  summaries.forEach((entry) => (entry.at = 0));
});
