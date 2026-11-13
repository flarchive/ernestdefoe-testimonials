import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { list, summary } from './data';
import { filtersFor, normalize, summaryParams, type WidgetSettings } from './settings';
import { claim, release } from './placement';
import { Avatar, Byline, Card, QuoteMark, Stars, unwatchWidth, watchWidth } from './ui';
import { dayIndex, t, tx } from '../util';
import { openShare } from '../share';

export type Kind = 'carousel' | 'wall' | 'spotlight' | 'rating';

export interface WidgetAttrs {
  kind: Kind;
  host: string;
  settings?: Record<string, any>;
  /** Drawn inside a host that brings its own frame and title (FoF): no box or heading of ours. */
  bare?: boolean;
}

/**
 * One component for all four widgets in every host (Bespoke, Page Builder, FoF, the stock spots).
 * It reads from the module-level store (data.ts), so a host that re-creates it on every redraw
 * costs nothing. Nothing at all is drawn while there's nothing to show, except a hint for staff.
 */
export default class TestimonialsWidget extends Component<WidgetAttrs> {
  private key = '';

  oninit(vnode: Mithril.Vnode<WidgetAttrs, this>) {
    super.oninit(vnode);
    // Read once: reading attrs at teardown could release a mount that was never claimed.
    this.key = `${this.attrs.host}:${this.attrs.kind}`;
  }

  oncreate(vnode: Mithril.VnodeDOM<WidgetAttrs, this>) {
    super.oncreate(vnode);
    claim(this.key);
    watchWidth(vnode.dom);
  }

  onremove(vnode: Mithril.VnodeDOM<WidgetAttrs, this>) {
    release(this.key);
    unwatchWidth(vnode.dom);
  }

  view() {
    const kind = this.attrs.kind;
    const s = normalize(this.attrs.settings, kind);
    const bare = !!this.attrs.bare;
    const body = kind === 'rating' ? rating(s, bare) : kind === 'spotlight' ? spotlight(s) : kind === 'wall' ? wall(s, bare) : carousel(s, bare);

    return (
      <div
        className={classList('TestimonialsWidget', `TestimonialsWidget--${kind}`, this.attrs.bare && 'TestimonialsWidget--bare')}
        data-host={this.attrs.host}
      >
        {body ?? (app.forum.attribute('testimonialsCanModerate') ? <p className="TestimonialsWidget-empty">{t('widget.empty_staff')}</p> : null)}
      </div>
    );
  }
}

function seeAll(): Mithril.Children {
  return (
    <Link className="TestimonialsWidget-more" href={app.route('testimonials')}>
      {t('widget.see_all')}
    </Link>
  );
}

function carousel(s: WidgetSettings, bare: boolean): Mithril.Children | null {
  const rows = list(filtersFor(s), s.count);
  if (!rows?.length) return null;
  return (
    <section
      className="TestimonialsCarousel"
      aria-roledescription="carousel"
      aria-label={tx('widget.carousel_label')}
      data-interval={s.interval ? String(s.interval) : undefined}
    >
      <div className="TestimonialsCarousel-head">
        {bare ? (
          <span />
        ) : (
          <span className="TestimonialsWidget-eyebrow">
            <QuoteMark />
            {s.title || t('widget.carousel_title')}
          </span>
        )}
        {rows.length > 1 ? (
          <span className="TestimonialsCarousel-arrows">
            <Button
              className="Button Button--icon TestimonialsCarousel-prev"
              icon="fas fa-chevron-left"
              aria-label={tx('widget.previous')}
              onclick={step(-1)}
            />
            <Button
              className="Button Button--icon TestimonialsCarousel-next"
              icon="fas fa-chevron-right"
              aria-label={tx('widget.next')}
              onclick={step(1)}
            />
          </span>
        ) : null}
      </div>
      <div className="TestimonialsCarousel-track" onscroll={syncDots} tabindex="0">
        {rows.map((row, i) => (
          <blockquote className="TestimonialsCarousel-slide" aria-label={tx('widget.slide', { index: i + 1, count: rows.length })}>
            <p className="TestimonialsCarousel-quote">{row.body()}</p>
            <footer className="TestimonialsCarousel-by">
              <Byline row={row} size={44} withSubject />
              {s.showRating ? <Stars value={row.rating()} size="md" /> : null}
            </footer>
          </blockquote>
        ))}
      </div>
      <div className="TestimonialsCarousel-foot">
        {rows.length > 1 ? (
          <span className="TestimonialsCarousel-dots">
            {rows.map((_, i) => (
              <button
                type="button"
                className={classList('TestimonialsCarousel-dot', i === 0 && 'is-on')}
                aria-label={tx('widget.go_to', { index: i + 1 })}
                onclick={goTo(i)}
              >
                <span />
              </button>
            ))}
          </span>
        ) : (
          <span />
        )}
        {seeAll()}
      </div>
    </section>
  );
}

function wall(s: WidgetSettings, bare: boolean): Mithril.Children | null {
  const rows = list(filtersFor(s), s.count);
  if (!rows?.length) return null;
  return (
    <section className="TestimonialsWall" style={{ '--columns': String(s.columns) }}>
      {bare ? null : (
        <div className="TestimonialsWall-head">
          <h2 className="TestimonialsWidget-title">{s.title || t('widget.wall_title')}</h2>
          {s.seeAll ? seeAll() : null}
        </div>
      )}
      <div className="TestimonialsWall-grid">
        {rows.map((row) => (
          <Card row={row} />
        ))}
      </div>
    </section>
  );
}

function spotlight(s: WidgetSettings): Mithril.Children | null {
  // Featured ones, newest featured first; with none featured, the newest that qualify.
  const featured = list(filtersFor(s, true), 7);
  const fallback = featured && !featured.length ? list(filtersFor(s), 7) : null;
  const rows = featured?.length ? featured : fallback;
  if (!rows?.length) return null;
  const row: Testimonial = s.pick === 'daily' ? rows[dayIndex(rows.length)] : rows[0];
  const author = row.author();
  return (
    <figure className="TestimonialsSpotlight">
      <Avatar row={row} size={84} />
      <div className="TestimonialsSpotlight-main">
        {row.isFeatured() ? (
          <span className="TestimonialsSpotlight-badge">
            <Icon name="fas fa-star" />
            {t('widget.featured')}
          </span>
        ) : null}
        <blockquote className="TestimonialsSpotlight-quote">
          <p>“{row.body()}”</p>
        </blockquote>
        <figcaption className="TestimonialsSpotlight-by">
          <span>
            <span className="TestimonialsSpotlight-name">{author.anonymous ? t('byline.anonymous') : author.name}</span>
            <span className="TestimonialsSpotlight-meta">
              {author.since ? t('byline.since', { year: author.since }) : null}
              {row.subject().label ? ` · ${row.subject().label}` : ''}
            </span>
          </span>
          {row.subject().type === 'post' && row.subject().url ? (
            <a className="TestimonialsWidget-more" href={row.subject().url!}>
              {t('widget.read_thread')}
            </a>
          ) : null}
        </figcaption>
      </div>
    </figure>
  );
}

function rating(s: WidgetSettings, bare: boolean): Mithril.Children | null {
  const data = summary(summaryParams(s));
  if (!data || !data.count) return null;
  const quotes = s.showQuote ? list({ ...filtersFor({ ...s, source: s.source === 'all' ? 'featured' : s.source }), sort: 'featured' }, 1) : null;
  const quote = quotes?.[0];
  const max = Math.max(1, ...Object.values(data.spread));
  const ratings = app.forum.attribute('testimonialsRatings') !== false && data.average !== null;

  return (
    <section className="TestimonialsRating" aria-label={tx('widget.rating_label')}>
      {bare ? null : <h2 className="TestimonialsWidget-eyebrow">{s.title || t('widget.rating_title')}</h2>}
      {ratings ? (
        <div className="TestimonialsRating-score">
          <span className="TestimonialsRating-average">{data.average!.toFixed(1)}</span>
          <span className="TestimonialsRating-of">
            <Stars value={data.average} size="md" />
            <span>{t('widget.count', { count: data.count })}</span>
          </span>
        </div>
      ) : (
        <p className="TestimonialsRating-count">{t('widget.count', { count: data.count })}</p>
      )}
      {ratings ? (
        <div className="TestimonialsRating-bars">
          {[5, 4, 3, 2, 1].map((n) => (
            <div className="TestimonialsRating-bar">
              <span>{n}</span>
              <span className="TestimonialsRating-track">
                <span style={{ width: `${Math.round(((data.spread[n] || 0) / max) * 100)}%` }} />
              </span>
              <span>{data.spread[n] || 0}</span>
            </div>
          ))}
        </div>
      ) : null}
      {ratings && data.aspects.length ? (
        <dl className="TestimonialsRating-aspects">
          {data.aspects.map((a) => (
            <div className="TestimonialsRating-aspect">
              <dt>{a.name}</dt>
              <dd>
                <span className="TestimonialsRating-track">
                  <span style={{ width: `${(a.average / 5) * 100}%` }} />
                </span>
                <span>{a.average.toFixed(1)}</span>
              </dd>
            </div>
          ))}
        </dl>
      ) : null}
      {quote ? (
        <figure className="TestimonialsRating-quote">
          <blockquote>“{quote.body()}”</blockquote>
          <figcaption>
            {quote.author().anonymous ? t('byline.anonymous') : quote.author().name}
            {quote.subject().label ? ` · ${quote.subject().label}` : ''}
          </figcaption>
        </figure>
      ) : null}
      {s.showButton && (app.forum.attribute('testimonialsCanCreate') || !app.session.user) ? (
        <Button className="Button Button--block TestimonialsRating-share" onclick={() => openShare()}>
          {t('share.button')}
        </Button>
      ) : null}
    </section>
  );
}

/* The carousel is driven from the DOM, not component state: hosts that re-create their widgets
   on every redraw (Bespoke) keep the slide, and one ticker serves every carousel on the page. */

function trackOf(e: Event): HTMLElement | null {
  return (e.currentTarget as HTMLElement).closest('.TestimonialsCarousel')?.querySelector('.TestimonialsCarousel-track') ?? null;
}

function step(direction: number) {
  return (e: Event) => {
    const track = trackOf(e);
    if (track) slideTo(track, current(track) + direction);
  };
}

function goTo(index: number) {
  return (e: Event) => {
    const track = trackOf(e);
    if (track) slideTo(track, index);
  };
}

function current(track: HTMLElement): number {
  return Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
}

function slideTo(track: HTMLElement, index: number): void {
  const count = track.children.length;
  const i = ((index % count) + count) % count;
  const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  track.scrollTo({ left: i * track.clientWidth, behavior: reduce ? 'auto' : 'smooth' });
}

function syncDots(e: Event): void {
  (e as any).redraw = false;
  const track = e.currentTarget as HTMLElement;
  const i = current(track);
  track
    .closest('.TestimonialsCarousel')
    ?.querySelectorAll('.TestimonialsCarousel-dot')
    .forEach((dot, n) => dot.classList.toggle('is-on', n === i));
}

let ticker = 0;
export function startTicker(): void {
  if (ticker || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
  ticker = window.setInterval(() => {
    if (document.hidden) return;
    document.querySelectorAll<HTMLElement>('.TestimonialsCarousel[data-interval]').forEach((el) => {
      // Paused while someone is reading it: hovered, or focus inside.
      if (el.matches(':hover') || el.contains(document.activeElement)) {
        el.dataset.idle = '0';
        return;
      }
      const idle = (parseInt(el.dataset.idle || '0', 10) || 0) + 1;
      if (idle >= (parseInt(el.dataset.interval || '8', 10) || 8)) {
        const track = el.querySelector<HTMLElement>('.TestimonialsCarousel-track');
        if (track && track.children.length > 1) slideTo(track, current(track) + 1);
        el.dataset.idle = '0';
      } else {
        el.dataset.idle = String(idle);
      }
    });
  }, 1000);
}
