import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Notification from 'flarum/forum/components/Notification';
import PostControls from 'flarum/forum/utils/PostControls';
import type Mithril from 'mithril';
import Testimonial from './Testimonial';
import TestimonialsWidget, { startTicker } from './widgets/Widget';
import { hostedElsewhere } from './widgets/placement';
import { registerHosts, registerPageBuilder } from './widgets/hosts';
import { openShare, ui } from './share';
import { t } from './util';

export { default as Testimonial } from './Testimonial';
export { default as TestimonialsWidget } from './widgets/Widget';
export { openShare } from './share';

/*
 * forum.js wires things up and carries the four widgets (they render on ordinary pages). The page,
 * the queue, the profile list and the form are one chunk (./components), fetched when first needed.
 */

const NOTIFICATIONS: Record<string, string> = {
  testimonialApproved: 'fas fa-check',
  testimonialFeatured: 'fas fa-star',
  testimonialDeclined: 'fas fa-xmark',
  testimonialEdited: 'fas fa-pen',
};

function notificationComponent(type: string) {
  return class extends Notification {
    icon() {
      return NOTIFICATIONS[type];
    }
    href() {
      return app.session.user ? app.route('user.testimonials', { username: app.session.user.slug() }) : app.route('testimonials');
    }
    content() {
      return t(`notification.${type}`);
    }
    excerpt() {
      const subject: any = (this.attrs.notification as any).subject();
      return subject?.attribute?.('declineReason') || subject?.attribute?.('body') || '';
    }
  };
}

const NUDGE_KEY = 'ernestdefoe-testimonials.nudge-dismissed';

function nudgeDismissed(): boolean {
  try {
    return localStorage.getItem(NUDGE_KEY) === '1';
  } catch (e) {
    return false;
  }
}

app.initializers.add('ernestdefoe-testimonials', () => {
  app.store.models.testimonials = Testimonial;

  // Queued for Page Builder now: it picks them up whenever it looks, whichever of us starts first.
  try {
    registerPageBuilder();
  } catch (e) {
    console.warn('[testimonials] Page Builder registration failed', e);
  }

  app.routes['testimonials'] = {
    path: '/testimonials',
    component: () => ui().then(async (chunk) => (await chunk.loadCore(), { default: chunk.TestimonialsPage })),
  };
  app.routes['testimonials.queue'] = {
    path: '/testimonials/queue',
    component: () => ui().then(async (chunk) => (await chunk.loadCore(), { default: chunk.QueuePage })),
  };
  app.routes['user.testimonials'] = {
    path: '/u/:username/testimonials',
    component: () => ui().then(async (chunk) => ({ default: await chunk.userTestimonialsPage() })),
  };

  Object.keys(NOTIFICATIONS).forEach((type) => (app.notificationComponents[type] = notificationComponent(type)));

  // String form: these live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    Object.entries(NOTIFICATIONS).forEach(([name, icon]) => items.add(name, { name, icon, label: t(`notification.${name}_preference`) }));
  });

  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    items.add('testimonials', LinkButton.component({ href: app.route('testimonials'), icon: 'fas fa-quote-left' }, t('nav')), 73);
    if (app.forum.attribute('testimonialsCanModerate')) {
      const pending = Number(app.forum.attribute('testimonialsPending') || 0);
      items.add(
        'testimonialsQueue',
        LinkButton.component({ href: app.route('testimonials.queue'), icon: 'fas fa-inbox' }, [
          t('nav_queue'),
          pending ? <span className="TestimonialsNav-count">{pending >= 100 ? '99+' : pending}</span> : null,
        ]),
        72
      );
    }
  });

  extend('flarum/forum/components/UserPage', 'navItems', function (this: any, items: ItemList<Mithril.Children>) {
    const user = this.user;
    if (!user) return;
    items.add(
      'testimonials',
      LinkButton.component({ href: app.route('user.testimonials', { username: user.slug() }), icon: 'fas fa-quote-left' }, t('profile.nav')),
      55
    );
  });

  // "Use as a testimonial" on your own posts.
  extend(PostControls, 'userControls', function (items: ItemList<Mithril.Children>, post: any) {
    if (!app.session.user || post.contentType() !== 'comment' || post.user() !== app.session.user || !app.forum.attribute('testimonialsCanCreate'))
      return;
    items.add(
      'testimonial',
      <Button
        icon="fas fa-quote-left"
        onclick={() =>
          openShare({
            subjectType: 'post',
            subjectId: Number(post.id()),
            subjectLabel: post.discussion()?.title?.() ?? '',
            body: plainExcerpt(post.contentHtml() || ''),
          })
        }
      >
        {t('post_control')}
      </Button>
    );
  });

  // The stock spots, for forums without a widget host (both off by default): a rating summary in
  // the index sidebar, a carousel at the top of the list. Each stands down while a host shows the same kind.
  extend('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('testimonialsStockSidebar') || hostedElsewhere('rating')) return;
    // Not beside the Testimonials page or queue: the page has its own score (on phones the sidebar sits above it).
    if (String(app.current.get('routeName') || '').startsWith('testimonials')) return;
    items.add('testimonialsRating', <TestimonialsWidget kind="rating" host="stock-sidebar" />, -60);
  });
  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    if (app.forum.attribute('testimonialsNudge') && !nudgeDismissed()) {
      items.add('testimonialsNudge', nudge(), 120);
    }
    if (app.forum.attribute('testimonialsStockTop') && !hostedElsewhere('carousel')) {
      items.add('testimonialsCarousel', <TestimonialsWidget kind="carousel" host="stock-top" />, 105);
    }
  });

  // After every initializer (Page Builder exposes its registry in its own), before the first draw.
  override(app, 'mount', function (original: (...args: any[]) => unknown, ...args: any[]) {
    registerHosts();
    startTicker();
    return original(...args);
  });
});

function nudge(): Mithril.Children {
  return (
    <aside className="TestimonialsNudge">
      <p>{t('nudge.text')}</p>
      <Button className="Button Button--primary" onclick={() => openShare()}>
        {t('share.button')}
      </Button>
      <Button
        className="Button Button--icon Button--link TestimonialsNudge-close"
        icon="fas fa-xmark"
        aria-label={app.translator.trans('core.lib.modal.close_button')}
        onclick={() => {
          try {
            localStorage.setItem(NUDGE_KEY, '1');
          } catch (e) {
            // Private mode: it comes back next visit.
          }
        }}
      />
    </aside>
  );
}

/**
 * A post's text for the form to start from: paragraphs and list items only. Quotes, code,
 * spoilers, embeds and anything hidden are left out; the member edits it before sending, and the
 * server keeps plain text only.
 */
function plainExcerpt(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc
    .querySelectorAll('blockquote, pre, code, script, style, iframe, details, [hidden], [aria-hidden="true"], [class*="spoiler"]')
    .forEach((el) => el.remove());
  const parts: string[] = [];
  doc.body.querySelectorAll('p, li').forEach((el) => {
    const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (text) parts.push(text);
  });
  return parts.join('\n\n').slice(0, Number(app.forum.attribute('testimonialsMaxLength') || 600));
}
