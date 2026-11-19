import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type Testimonial from './Testimonial';

export const EXT = 'ernestdefoe-testimonials';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

export const api = (path: string) => `${app.forum.attribute('apiUrl')}/${path}`;

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `${EXT}.forum.refusal.${code}`;
  if (code && extractText(app.translator.trans(key)) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return tx('refusal.generic');
}

/** Shows the error, then passes it on (an `errorHandler` for app.request and model saves). */
export function alertError(error: any): never {
  app.alerts.show({ type: 'error' }, errorText(error));
  throw error;
}

/** POST /api/testimonials/{id}/moderate; the answer is the testimonial, pushed into the store. */
export function moderate(row: Testimonial, action: string, extra: Record<string, unknown> = {}): Promise<Testimonial> {
  return app
    .request<any>({ method: 'POST', url: api(`testimonials/${row.id()}/moderate`), body: { action, ...extra }, errorHandler: alertError })
    .then((payload) => {
      // The server has said yes: nothing after this may turn it into a failure on screen.
      let model = row;
      try {
        model = app.store.pushPayload(payload) as unknown as Testimonial;
      } catch {
        // The change is saved; if the answer can't be read into the store, the next load shows it.
      }
      changed();
      return model;
    });
}

/** Something changed: widgets and summaries ask again next time they're drawn. */
const listeners: Array<() => void> = [];
export function onChange(fn: () => void): void {
  listeners.push(fn);
}
export function changed(): void {
  listeners.forEach((fn) => {
    try {
      fn();
    } catch {
      // One widget failing to refresh must not stop the others; it asks again when next drawn.
    }
  });
}

/** Day of the year: today's featured testimonial is the same for everyone today. */
export function dayIndex(length: number): number {
  const now = new Date();
  const day = Math.floor((now.getTime() - new Date(now.getFullYear(), 0, 0).getTime()) / 86400000);
  return length ? day % length : 0;
}
