import app from 'flarum/forum/app';

/** The page, the queue, the profile list and the form: one chunk, fetched the first time any is needed. */
export const ui = () => import('./components');

export interface SharePrefill {
  subjectType?: 'community' | 'tag' | 'depot' | 'post';
  subjectId?: number;
  subjectLabel?: string;
  body?: string;
  /** Editing an existing one (the author, or a moderator fixing the wording). */
  testimonial?: any;
}

/** "Share your experience": the form, or the log-in dialog for a guest. */
export function openShare(prefill: SharePrefill = {}): void {
  if (!app.session.user) {
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return;
  }
  ui().then(({ ShareModal }) => app.modal.show(ShareModal, prefill));
}
