import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import PostControls from 'flarum/forum/utils/PostControls';
import type Mithril from 'mithril';
import TestimonialsWidget, { startTicker } from './widgets/Widget';
import { hostedElsewhere } from './widgets/placement';
import { registerHosts, registerPageBuilder } from './widgets/hosts';
import { openShare } from './share';
import { t, tx } from './util';
import { NOTIFICATIONS } from './extend';

export { default as Testimonial } from './Testimonial';
export { default as TestimonialsWidget } from './widgets/Widget';
export { openShare } from './share';

/*
 * forum.js wires things up and carries the four widgets (they render on ordinary pages). The page,
 * the queue, the profile list and the form are one chunk (./components), fetched when first needed.
 */

export { default as extend } from './extend';

const NUDGE_KEY = 'ernestdefoe-testimonials.nudge-dismissed';

function nudgeDismissed(): boolean {
  try {
    return localStorage.getItem(NUDGE_KEY) === '1';
  } catch (e) {
    return false;
  }
}

app.initializers.add('ernestdefoe-testimonials', () => {
  // Queued for Page Builder now: it picks them up whenever it looks, whichever of us starts first.
  try {
    registerPageBuilder();
  } catch (e) {
    console.warn('[testimonials] Page Builder registration failed', e);
  }

  // String form: these live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    Object.entries(NOTIFICATIONS).forEach(([name, icon]) => items.add(name, { name, icon, label: t(`notification.${name}_preference`) }));
  });

  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    items.add('testimonials', LinkButton.component({ href: app.route('testimonials'), icon: 'fas fa-quote-left' }, t('nav')), 73);
    if (app.forum.attribute('testimonialsCanModerate')) {
      const count = Number(app.forum.attribute('testimonialsPending'));
      const pending = Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
      items.add(
        'testimonialsQueue',
        // The label is the plain text alone, and the count rides on an attribute that CSS draws as
        // a badge. Core's SelectDropdown shows the active item's label a second time in its toggle,
        // so a badge element there would be one vnode in two places: a change in the count (an
        // approval) threw in the redraw and froze the page. And menus that read the label as text
        // (Header Nav's overflow menu) would glue the count, or "null", onto the name.
        LinkButton.component(
          {
            href: app.route('testimonials.queue'),
            icon: 'fas fa-inbox',
            className: 'TestimonialsNav-queue',
            'data-count': pending ? (pending >= 100 ? '99+' : String(pending)) : undefined,
          },
          t('nav_queue')
        ),
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
});

// Last of the initializers (Page Builder exposes its registry in its own), before the first draw.
app.initializers.add(
  'ernestdefoe-testimonials-hosts',
  () => {
    registerHosts();
    startTicker();
  },
  -100
);

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
        aria-label={tx('nudge.close')}
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
