import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import type { SharePrefill } from '../share';
import { alertError, api, changed, t, tx } from '../util';

type SubjectType = 'community' | 'tag' | 'depot' | 'post';
type Byline = 'full' | 'first' | 'anonymous';

export interface ShareModalAttrs extends IFormModalAttrs, SharePrefill {}

/**
 * "Share your experience": what it's about, the stars (overall and per aspect), a headline, the
 * words, how the name shows, and consent. Also the author's edit, and a moderator's wording fix
 * (headline and words only). A preview beside the form shows the card as others will see it.
 */
export default class ShareModal extends FormModal<ShareModalAttrs> {
  editing: Testimonial | null = null;
  wordingOnly = false;

  subjectType: SubjectType = 'community';
  subjectId: number | null = null;
  subjectLabel = '';
  rating = 0;
  aspects: Record<number, number> = {};
  headline = '';
  body = '';
  byline: Byline = 'full';
  consent = false;
  consentExternal = false;

  // The download picker.
  term = '';
  found: Array<{ id: number; title: string }> = [];
  private searchTimer = 0;

  oninit(vnode: Mithril.Vnode<ShareModalAttrs, this>) {
    super.oninit(vnode);
    const a = this.attrs;
    if (a.testimonial) {
      const row: Testimonial = a.testimonial;
      this.editing = row;
      this.wordingOnly = !row.canEdit() && row.canEditWording();
      this.subjectType = row.subject().type;
      this.subjectId = row.subject().id;
      this.subjectLabel = row.subject().label || '';
      this.rating = row.rating() || 0;
      (row.aspects() || []).forEach((r) => (this.aspects[r.id] = r.rating));
      this.headline = row.headline() || '';
      this.body = row.body();
      this.byline = row.byline();
      this.consent = true;
      this.consentExternal = !!row.consentExternal();
    } else {
      this.subjectType = a.subjectType || 'community';
      this.subjectId = a.subjectId ?? null;
      this.subjectLabel = a.subjectLabel || '';
      this.body = a.body || '';
    }
  }

  className() {
    return 'ShareTestimonialModal Modal--large';
  }

  title() {
    return this.editing ? (this.wordingOnly ? t('share.title_wording') : t('share.title_edit')) : t('share.title');
  }

  content() {
    return (
      <div className="ShareTestimonial">
        <div className="Modal-body ShareTestimonial-form">
          <p className="ShareTestimonial-note">{this.wordingOnly ? t('share.wording_note') : t('share.intro')}</p>
          {!this.editing ? this.subjectField() : null}
          {!this.wordingOnly && app.forum.attribute('testimonialsRatings') !== false ? this.ratingFields() : null}
          {this.headlineField()}
          {this.bodyField()}
          {!this.wordingOnly ? this.bylineField() : null}
          {!this.editing ? this.consentFields() : null}

          <div className="Form-group ShareTestimonial-actions">
            <p className="helpText">{this.wordingOnly ? t('share.wording_footer') : t('share.footer')}</p>
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!this.ready()}>
              {this.editing ? t('share.save') : t('share.submit')}
            </Button>
          </div>
        </div>
        <aside className="ShareTestimonial-preview" aria-label={tx('share.preview')}>
          <span className="ShareTestimonial-previewLabel">{t('share.preview')}</span>
          {this.preview()}
        </aside>
      </div>
    );
  }

  headlineField(): Mithril.Children {
    return (
      <div className="Form-group">
        <label htmlFor="testimonial-headline">
          {t('share.headline')} <span className="ShareTestimonial-optional">{t('share.optional')}</span>
        </label>
        <input
          id="testimonial-headline"
          className="FormControl"
          maxlength={120}
          value={this.headline}
          placeholder={tx('share.headline_placeholder')}
          oninput={(e: InputEvent) => (this.headline = (e.target as HTMLInputElement).value)}
        />
      </div>
    );
  }

  bodyField(): Mithril.Children {
    const min = Number(app.forum.attribute('testimonialsMinLength') || 40);
    const max = Number(app.forum.attribute('testimonialsMaxLength') || 600);
    const length = this.body.trim().length;
    return (
      <div className="Form-group">
        <label htmlFor="testimonial-body">{t('share.body')}</label>
        <textarea
          id="testimonial-body"
          className="FormControl ShareTestimonial-body"
          rows={5}
          maxlength={max}
          value={this.body}
          placeholder={tx('share.body_placeholder')}
          oninput={(e: InputEvent) => (this.body = (e.target as HTMLTextAreaElement).value)}
        />
        <p className="helpText ShareTestimonial-count">
          <span>{t('share.body_help', { min })}</span>
          <span className={classList(length > 0 && length < min && 'is-short')}>{t('share.counter', { count: length, max })}</span>
        </p>
      </div>
    );
  }

  consentFields(): Mithril.Children {
    return (
      <div className="Form-group ShareTestimonial-consent">
        <label className="checkbox">
          <input type="checkbox" checked={this.consent} onchange={(e: Event) => (this.consent = (e.target as HTMLInputElement).checked)} />
          {t('share.consent')}
        </label>
        <label className="checkbox">
          <input
            type="checkbox"
            checked={this.consentExternal}
            onchange={(e: Event) => (this.consentExternal = (e.target as HTMLInputElement).checked)}
          />
          {t('share.consent_external')}
        </label>
      </div>
    );
  }

  /** What it's about: the community, a tag, a download, or (from a post's menu) that post. */
  subjectField(): Mithril.Children {
    const tags = 'flarum-tags' in (flarum.extensions || {}) ? app.store.all<any>('tags') : [];
    const choices: Array<[SubjectType, string]> = [['community', tx('share.about_community')]];
    if (tags.length) choices.push(['tag', tx('share.about_tag')]);
    if (app.forum.attribute('testimonialsDepot')) choices.push(['depot', tx('share.about_depot')]);
    if (this.subjectType === 'post') choices.push(['post', tx('share.about_post')]);

    return (
      <fieldset className="Form-group ShareTestimonial-subject">
        <legend>{t('share.about')}</legend>
        {choices.length > 1 ? (
          <div className="ShareTestimonial-segments" role="group">
            {choices.map(([type, label]) => (
              <button
                type="button"
                className={classList('ShareTestimonial-segment', this.subjectType === type && 'is-on')}
                aria-pressed={this.subjectType === type ? 'true' : 'false'}
                onclick={() => this.pickSubject(type)}
              >
                {label}
              </button>
            ))}
          </div>
        ) : null}
        {this.subjectType === 'tag' ? this.tagPicker(tags) : null}
        {this.subjectType === 'depot' ? this.depotPicker() : null}
        {this.subjectType === 'post' ? (
          <p className="ShareTestimonial-linked">
            <Icon name="fas fa-link" /> {t('share.linked_post', { title: this.subjectLabel })}
          </p>
        ) : null}
      </fieldset>
    );
  }

  pickSubject(type: SubjectType): void {
    if (this.subjectType === type) return;
    this.subjectType = type;
    this.subjectId = null;
    this.subjectLabel = '';
  }

  tagPicker(tags: any[]): Mithril.Children {
    return (
      <select
        className="FormControl"
        aria-label={tx('share.about_tag')}
        value={this.subjectId ? String(this.subjectId) : ''}
        onchange={(e: Event) => {
          const id = Number((e.target as HTMLSelectElement).value) || null;
          this.subjectId = id;
          this.subjectLabel = id ? app.store.getById<any>('tags', String(id))?.name() || '' : '';
        }}
      >
        <option value="">{tx('share.pick_tag')}</option>
        {tags
          .filter((tag: any) => !tag.isHidden?.())
          .map((tag: any) => (
            // Keyed as strings: integer-like keys get re-sorted by JS.
            <option value={String(tag.id())}>{tag.name()}</option>
          ))}
      </select>
    );
  }

  depotPicker(): Mithril.Children {
    if (this.subjectId) {
      return (
        <p className="ShareTestimonial-linked">
          <Icon name="fas fa-box-open" /> {this.subjectLabel}{' '}
          <Button className="Button Button--link" onclick={() => ((this.subjectId = null), (this.subjectLabel = ''))}>
            {t('share.change')}
          </Button>
        </p>
      );
    }
    return (
      <div className="ShareTestimonial-depot">
        <input
          className="FormControl"
          type="search"
          aria-label={tx('share.find_download')}
          placeholder={tx('share.find_download')}
          value={this.term}
          oninput={(e: InputEvent) => {
            this.term = (e.target as HTMLInputElement).value;
            clearTimeout(this.searchTimer);
            this.searchTimer = window.setTimeout(() => this.search(), 300);
          }}
        />
        {this.found.length ? (
          <ul className="ShareTestimonial-results">
            {this.found.map((f) => (
              <li>
                <button type="button" onclick={() => ((this.subjectId = f.id), (this.subjectLabel = f.title), (this.found = []))}>
                  {f.title}
                </button>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    );
  }

  search(): void {
    const q = this.term.trim();
    if (q.length < 2) {
      this.found = [];
      m.redraw();
      return;
    }
    app
      .request<any>({ method: 'GET', url: api('depot/browse'), params: { q }, errorHandler: () => {} } as any)
      .then((body) => (this.found = (body?.files || []).slice(0, 8).map((f: any) => ({ id: Number(f.id), title: String(f.title) }))))
      .catch(() => (this.found = []))
      .finally(() => m.redraw());
  }

  ratingFields(): Mithril.Children {
    const aspects = app.forum.attribute<Array<{ id: number; name: string }>>('testimonialsAspects') || [];
    return (
      <div className="Form-group ShareTestimonial-ratings">
        <div className="ShareTestimonial-rateRow ShareTestimonial-rateRow--overall">
          <span className="ShareTestimonial-rateLabel">
            {t('share.rating')} <span className="ShareTestimonial-optional">{t('share.optional')}</span>
          </span>
          {this.starInput(this.rating, (n) => (this.rating = n), tx('share.rating'), true)}
        </div>
        {aspects.length ? (
          <div className="ShareTestimonial-aspects">
            {aspects.map((aspect) => (
              <div className="ShareTestimonial-rateRow">
                <span className="ShareTestimonial-rateLabel">{aspect.name}</span>
                {this.starInput(this.aspects[aspect.id] || 0, (n) => (this.aspects[aspect.id] = n), aspect.name, false)}
              </div>
            ))}
          </div>
        ) : null}
      </div>
    );
  }

  /** Five real buttons (a tap works on every phone); the same star again clears it. */
  starInput(value: number, set: (n: number) => void, label: string, big: boolean): Mithril.Children {
    return (
      <span className={classList('ShareTestimonial-stars', big && 'is-big')} role="group" aria-label={label}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            type="button"
            className={classList('ShareTestimonial-star', n <= value && 'is-on')}
            aria-label={tx('share.rate_n', { label, rating: n })}
            aria-pressed={n === value ? 'true' : 'false'}
            onclick={() => set(value === n ? 0 : n)}
          >
            ★
          </button>
        ))}
      </span>
    );
  }

  bylineField(): Mithril.Children {
    const user = app.session.user!;
    const name = user.displayName();
    const options: Array<[Byline, string, string]> = [
      ['full', tx('share.byline_full'), name],
      ['first', tx('share.byline_first'), name.split(/\s+/)[0]],
    ];
    if (app.forum.attribute('testimonialsAnonymous') !== false) {
      options.push(['anonymous', tx('share.byline_anonymous'), tx('byline.anonymous')]);
    }
    return (
      <fieldset className="Form-group ShareTestimonial-byline">
        <legend>{t('share.byline')}</legend>
        <div className="ShareTestimonial-bylines">
          {options.map(([value, label, example]) => (
            <label className={classList('ShareTestimonial-bylineOption', this.byline === value && 'is-on')}>
              <input type="radio" name="testimonial-byline" checked={this.byline === value} onchange={() => (this.byline = value)} />
              <span>
                <strong>{label}</strong>
                <span>{example}</span>
              </span>
            </label>
          ))}
        </div>
        {this.byline !== 'full' ? (
          <p className="helpText">{this.byline === 'anonymous' ? t('share.anonymous_help') : t('share.first_help')}</p>
        ) : null}
      </fieldset>
    );
  }

  preview(): Mithril.Children {
    const user = app.session.user!;
    const name =
      this.byline === 'anonymous' ? tx('byline.anonymous') : this.byline === 'first' ? user.displayName().split(/\s+/)[0] : user.displayName();
    const since = user.joinTime() ? String(user.joinTime()!.getFullYear()) : '';
    const about = this.subjectType === 'community' ? tx('subject.community') : this.subjectLabel;
    return (
      <article className="TestimonialCard">
        <div className="TestimonialCard-top">
          {this.rating ? (
            <span className="TestimonialStars TestimonialStars--sm" aria-hidden="true">
              {[1, 2, 3, 4, 5].map((i) => (
                <span className={classList('TestimonialStars-star', i <= this.rating && 'is-on')}>★</span>
              ))}
            </span>
          ) : (
            <span />
          )}
          {about ? <span className="TestimonialChip">{about}</span> : null}
        </div>
        {this.headline.trim() ? <h3 className="TestimonialCard-headline">{this.headline}</h3> : null}
        <p className="TestimonialCard-body">{this.body.trim() || tx('share.preview_empty')}</p>
        <div className="TestimonialByline">
          {this.byline === 'full' && user.avatarUrl() ? (
            <img className="TestimonialAvatar" src={user.avatarUrl()!} alt="" style={{ width: '32px', height: '32px' }} />
          ) : (
            <span
              className={classList('TestimonialAvatar', this.byline === 'anonymous' && 'TestimonialAvatar--anonymous')}
              style={{ width: '32px', height: '32px', '--avatar-bg': user.color() || undefined }}
              aria-hidden="true"
            >
              {this.byline === 'anonymous' ? <Icon name="fas fa-user" /> : name.charAt(0).toUpperCase()}
            </span>
          )}
          <div className="TestimonialByline-text">
            <span className="TestimonialByline-name">{name}</span>
            <span className="TestimonialByline-meta">{since && this.byline !== 'anonymous' ? t('byline.since', { year: since }) : null}</span>
          </div>
        </div>
      </article>
    );
  }

  ready(): boolean {
    const min = Number(app.forum.attribute('testimonialsMinLength') || 40);
    if (this.body.trim().length < min) return false;
    if (this.editing) return true;
    if (!this.consent) return false;
    return this.subjectType === 'community' || !!this.subjectId;
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.ready()) return;
    this.loading = true;

    const attrs: Record<string, any> = { headline: this.headline, body: this.body };
    if (!this.wordingOnly) {
      Object.assign(attrs, {
        rating: this.rating || null,
        aspects: Object.entries(this.aspects)
          .filter(([, r]) => r > 0)
          .map(([id, rating]) => ({ id: Number(id), rating })),
        byline: this.byline,
        consentExternal: this.consentExternal,
      });
    }
    if (!this.editing) {
      Object.assign(attrs, { consent: true, subjectType: this.subjectType, subjectId: this.subjectId });
    }

    const model: any = this.editing || app.store.createRecord('testimonials');
    model
      .save(attrs, { errorHandler: alertError })
      .then((saved: Testimonial) => {
        changed();
        this.hide();
        const live = saved.status() === 'approved';
        app.alerts.show({ type: 'success' }, this.wordingOnly ? t('share.saved_wording') : live ? t('share.saved_live') : t('share.saved_pending'));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
