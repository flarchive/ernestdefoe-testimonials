import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { Card, RatingAspects, RatingBars, RatingScore } from '../widgets/ui';
import type { Summary } from '../widgets/data';
import { api, onChange, t, tx } from '../util';
import { openShare } from '../share';
import { IndexSidebar, PageStructure } from './core';
import Controls from './Controls';

const PAGE = 24;

let current: TestimonialsPage | null = null;
// A save or an approval anywhere: the open page asks again.
onChange(() => current?.reload());

/**
 * /testimonials: the average, the spread and each aspect, a chip per subject, the featured ones,
 * then every live testimonial, newest first, 24 at a time. Built in core's page structure with the
 * forum's own sidebar, so any theme dresses it.
 */
export default class TestimonialsPage extends Page {
  chip = 'all';
  rows: Testimonial[] = [];
  featured: Testimonial[] = [];
  summary: Summary | null = null;
  loading = true;
  more = false;
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('testimonials', tx('page.title'));
    app.setTitle(tx('page.title'));
    this.bodyClass = 'App--testimonials';
    this.chip = m.route.param('about') || 'all';
    current = this;
    this.reload();
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    if (current === this) current = null;
  }

  reload(): void {
    app
      // The score and chips are extras: without them the page still lists everything, so a failure
      // here stays quiet rather than putting an error over a working page.
      .request<{ meta: Summary }>({
        method: 'GET',
        url: api('testimonials/summary'),
        params: { filter: { subjects: 1 } },
        errorHandler: () => {},
      } as any)
      .then((doc) => (this.summary = doc.meta))
      .catch(() => {})
      .finally(() => m.redraw());
    app.store
      .find<Testimonial[]>('testimonials', { filter: { featured: 1 }, page: { limit: 3 } })
      .then((rows) => (this.featured = [...(rows as any)]))
      // Flarum's request handler has already shown the error; the page lists the rest.
      .catch(() => {})
      .finally(() => m.redraw());
    this.load(0);
  }

  filters(): Record<string, string | number> {
    if (this.chip === 'rated') return { minRating: 4 };
    if (this.chip === 'community') return { subject: 'community' };
    const [type, id] = this.chip.split(':');
    if ((type === 'tag' || type === 'depot') && id) return { subject: type, subjectId: id };
    // "All" shows the featured ones in their own strip above.
    return { notFeatured: 1 };
  }

  load(offset: number): void {
    const ticket = ++this.ticket;
    this.loading = true;
    app.store
      .find<Testimonial[]>('testimonials', { filter: this.filters(), page: { offset, limit: PAGE } })
      .then((rows: any) => {
        if (ticket !== this.ticket) return;
        this.rows = offset ? [...this.rows, ...rows] : [...rows];
        this.more = !!rows.payload?.links?.next;
      })
      // Flarum's request handler has already shown the error; the list keeps what it had.
      .catch(() => {})
      .finally(() => {
        if (ticket !== this.ticket) return;
        this.loading = false;
        m.redraw();
      });
  }

  pick(chip: string): void {
    if (chip === this.chip) return;
    this.chip = chip;
    this.rows = [];
    this.load(0);
  }

  view() {
    return (
      <PageStructure className="TestimonialsPage" sidebar={() => <IndexSidebar />}>
        {this.hero()}
        {this.chips()}
        {this.chip === 'all' && this.featured.length ? (
          <section className="TestimonialsPage-featured">
            <h2 className="TestimonialsPage-label">
              <span className="TestimonialStars-star is-on" aria-hidden="true">
                ★
              </span>{' '}
              {t('page.featured')}
            </h2>
            <div className="TestimonialsPage-featuredGrid">
              {this.featured.map((row) => (
                <Card row={row} large footer={<Controls row={row} />} />
              ))}
            </div>
          </section>
        ) : null}
        <section className="TestimonialsPage-all">
          <h2 className="TestimonialsPage-label">{t('page.all')}</h2>
          {this.rows.length ? (
            <div className="TestimonialsPage-grid">
              {this.rows.map((row) => (
                <Card row={row} footer={<Controls row={row} />} />
              ))}
            </div>
          ) : this.loading ? null : (
            <p className="TestimonialsPage-empty">{t('page.empty')}</p>
          )}
          {this.loading ? <LoadingIndicator /> : null}
          {this.more && !this.loading ? (
            <div className="TestimonialsPage-more">
              <Button className="Button" onclick={() => this.load(this.rows.length)}>
                {t('page.load_more')}
              </Button>
            </div>
          ) : null}
        </section>
      </PageStructure>
    );
  }

  hero(): Mithril.Children {
    const s = this.summary;
    const ratings = app.forum.attribute('testimonialsRatings') !== false && s && s.average !== null;
    return (
      <header className="TestimonialsPage-hero">
        <div className="TestimonialsPage-intro">
          <span className="TestimonialsWidget-eyebrow">{t('page.eyebrow')}</span>
          <h1 className="TestimonialsPage-title">{t('page.heading', { forum: app.forum.attribute('title') })}</h1>
          <p className="TestimonialsPage-lead">{t('page.lead')}</p>
          <div className="TestimonialsPage-actions">
            {app.forum.attribute('testimonialsCanCreate') || !app.session.user ? (
              <Button className="Button Button--primary" icon="fas fa-pen" onclick={() => openShare()}>
                {t('share.button')}
              </Button>
            ) : null}
            {app.session.user ? (
              <Link className="TestimonialsPage-mine" href={app.route('user.testimonials', { username: app.session.user.slug() })}>
                {t('page.yours')}
              </Link>
            ) : null}
          </div>
        </div>
        {ratings ? (
          <div className="TestimonialsPage-score">
            <RatingScore summary={s!} />
            <RatingBars summary={s!} />
            {s!.aspects.length ? <RatingAspects summary={s!} /> : null}
          </div>
        ) : null}
      </header>
    );
  }

  chips(): Mithril.Children {
    const s = this.summary;
    if (!s || !s.count) return null;
    const chips: Array<[string, Mithril.Children, number | null]> = [['all', t('page.chip_all'), s.count]];
    (s.subjects || []).forEach((sub) => {
      if (sub.type === 'community') chips.push(['community', t('subject.community'), sub.count]);
      else if (sub.label) chips.push([`${sub.type}:${sub.id}`, sub.label, sub.count]);
    });
    if (app.forum.attribute('testimonialsRatings') !== false && s.rated) chips.push(['rated', t('page.chip_rated'), null]);
    if (chips.length < 3) return null;

    return (
      <nav className="TestimonialsPage-chips" aria-label={tx('page.filter_label')}>
        {chips.map(([key, label, count]) => (
          <button
            type="button"
            className={classList('TestimonialsPage-chip', this.chip === key && 'is-on')}
            aria-pressed={this.chip === key ? 'true' : 'false'}
            onclick={() => this.pick(key)}
          >
            {label}
            {count !== null ? <span className="TestimonialsPage-chipCount">{count}</span> : null}
          </button>
        ))}
      </nav>
    );
  }
}
