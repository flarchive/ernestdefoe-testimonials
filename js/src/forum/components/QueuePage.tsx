import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { Avatar, Stars, SubjectChip } from '../widgets/ui';
import { changed, moderate, t, tx } from '../util';
import { openShare } from '../share';
import { IndexSidebar, PageStructure } from './core';

type Tab = 'pending' | 'approved' | 'featured' | 'declined' | 'spam' | 'removed';
const TABS: Tab[] = ['pending', 'approved', 'featured', 'declined', 'spam', 'removed'];
const REASONS = ['short', 'advert', 'names_member', 'off_topic', 'other'];

/**
 * /testimonials/queue (moderators): read each one before it goes live. Approve, approve and
 * feature, fix the wording, decline with a reason the member sees, or mark as spam (no alert).
 * Checkboxes for bulk approve/decline; J/K move, A approves, F features, D opens the decline reasons.
 */
export default class QueuePage extends Page {
  tab: Tab = 'pending';
  rows: Testimonial[] = [];
  loading = true;
  more = false;
  selected = new Set<string>();
  focus = 0;
  declining: string | null = null;
  reason = 'short';
  note = '';
  busy = new Set<string>();
  private keys = (e: KeyboardEvent) => this.onkey(e);

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    if (!app.forum.attribute('testimonialsCanModerate')) {
      m.route.set(app.route('testimonials'));
      return;
    }
    app.history.push('testimonials.queue', tx('queue.title'));
    app.setTitle(tx('queue.title'));
    this.bodyClass = 'App--testimonialsQueue';
    const tab = m.route.param('tab') as Tab;
    if (TABS.includes(tab)) this.tab = tab;
    this.load(0);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    document.addEventListener('keydown', this.keys);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    document.removeEventListener('keydown', this.keys);
  }

  load(offset: number): void {
    this.loading = true;
    app.store
      .find<Testimonial[]>('testimonials', { filter: { status: this.tab }, page: { offset, limit: 24 } })
      .then((rows: any) => {
        this.rows = offset ? [...this.rows, ...rows] : [...rows];
        this.more = !!rows.payload?.links?.next;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  switchTab(tab: Tab): void {
    if (tab === this.tab) return;
    this.tab = tab;
    this.rows = [];
    this.selected.clear();
    this.focus = 0;
    this.declining = null;
    this.load(0);
  }

  /** Act on one, then drop it from this tab (it belongs to another now) and refresh the counts. */
  act(row: Testimonial, action: string, extra: Record<string, unknown> = {}): Promise<void> {
    const id = row.id()!;
    this.busy.add(id);
    return moderate(row, action, extra)
      .then(() => {
        const stays = (this.tab === 'approved' && action === 'feature') || (this.tab === 'featured' && (action === 'up' || action === 'down'));
        if (!stays) {
          this.rows = this.rows.filter((r) => r !== row);
          this.selected.delete(id);
          this.focus = Math.min(this.focus, Math.max(0, this.rows.length - 1));
        }
        if (this.declining === id) this.declining = null;
        if (this.tab === 'pending') {
          const pending = Math.max(0, Number(app.forum.attribute('testimonialsPending') || 0) - 1);
          (app.forum as any).pushAttributes({ testimonialsPending: pending });
        }
        if (this.tab === 'featured' && (action === 'up' || action === 'down')) this.load(0);
      })
      .catch(() => {})
      .finally(() => {
        this.busy.delete(id);
        m.redraw();
      });
  }

  bulk(action: 'approve' | 'decline'): void {
    const rows = this.rows.filter((r) => this.selected.has(r.id()!));
    rows
      .reduce<Promise<void>>(
        (p, row) => p.then(() => this.act(row, action, action === 'decline' ? { reason: tx('queue.reasons.other_text') } : {})),
        Promise.resolve()
      )
      .then(changed);
  }

  onkey(e: KeyboardEvent): void {
    const target = e.target as HTMLElement;
    if (e.metaKey || e.ctrlKey || e.altKey || target.closest('input, textarea, select, [contenteditable]') || app.modal.isModalOpen?.()) return;
    const row = this.rows[this.focus];
    const key = e.key.toLowerCase();
    if (key === 'j' || key === 'k') {
      this.focus = Math.max(0, Math.min(this.rows.length - 1, this.focus + (key === 'j' ? 1 : -1)));
      document.querySelectorAll('.QueueRow')[this.focus]?.scrollIntoView({ block: 'nearest' });
    } else if (row && this.tab === 'pending' && key === 'a') {
      this.act(row, 'approve').then(changed);
    } else if (row && this.tab === 'pending' && key === 'f') {
      this.act(row, 'feature').then(changed);
    } else if (row && this.tab === 'pending' && key === 'd') {
      this.declining = row.id()!;
    } else {
      return;
    }
    e.preventDefault();
    m.redraw();
  }

  view() {
    if (!app.forum.attribute('testimonialsCanModerate')) return <div />;
    const pending = Number(app.forum.attribute('testimonialsPending') || 0);
    const allSelected = this.rows.length > 0 && this.rows.every((r) => this.selected.has(r.id()!));

    return (
      <PageStructure className="TestimonialsQueue" sidebar={() => <IndexSidebar />}>
        <header className="TestimonialsQueue-header">
          <h1 className="TestimonialsPage-title">{t('queue.title')}</h1>
          <p className="TestimonialsPage-lead">{t('queue.lead')}</p>
        </header>

        <nav className="TestimonialsQueue-tabs" role="tablist">
          {TABS.map((tab) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.tab === tab ? 'true' : 'false'}
              className={classList('TestimonialsQueue-tab', this.tab === tab && 'is-on')}
              onclick={() => this.switchTab(tab)}
            >
              {t(`queue.tabs.${tab}`)}
              {tab === 'pending' && pending ? <span className="TestimonialsNav-count">{pending}</span> : null}
            </button>
          ))}
        </nav>

        {this.tab === 'pending' && this.rows.length ? (
          <div className="TestimonialsQueue-toolbar">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={allSelected}
                onchange={(e: Event) => {
                  this.selected = new Set((e.target as HTMLInputElement).checked ? this.rows.map((r) => r.id()!) : []);
                }}
              />
              {t('queue.select_all')}
            </label>
            <Button className="Button" disabled={!this.selected.size} onclick={() => this.bulk('approve')}>
              {t('queue.approve_selected', { count: this.selected.size })}
            </Button>
            <Button className="Button" disabled={!this.selected.size} onclick={() => this.bulk('decline')}>
              {t('queue.decline_selected', { count: this.selected.size })}
            </Button>
            <span className="TestimonialsQueue-keys">
              <kbd>J</kbd>
              <kbd>K</kbd> {t('queue.keys_move')} <kbd>A</kbd> {t('queue.keys_approve')} <kbd>F</kbd> {t('queue.keys_feature')} <kbd>D</kbd>{' '}
              {t('queue.keys_decline')}
            </span>
          </div>
        ) : null}

        <div className="TestimonialsQueue-list">
          {this.rows.map((row, i) => this.row(row, i))}
          {!this.loading && !this.rows.length ? <p className="TestimonialsPage-empty">{t(`queue.empty.${this.tab}`)}</p> : null}
          {this.loading ? <LoadingIndicator /> : null}
          {this.more && !this.loading ? (
            <div className="TestimonialsPage-more">
              <Button className="Button" onclick={() => this.load(this.rows.length)}>
                {t('page.load_more')}
              </Button>
            </div>
          ) : null}
        </div>
      </PageStructure>
    );
  }

  row(row: Testimonial, index: number): Mithril.Children {
    const id = row.id()!;
    const author = row.author();
    const busy = this.busy.has(id);
    const meta = [
      author.commentCount !== undefined ? t('queue.posts', { count: author.commentCount }) : null,
      author.joinedAt
        ? t('queue.joined', { date: new Date(author.joinedAt).toLocaleDateString(undefined, { month: 'short', year: 'numeric' }) })
        : null,
      author.trustLevel !== null && author.trustLevel !== undefined ? t('queue.trust_level', { level: author.trustLevel }) : null,
    ].filter(Boolean);

    return (
      <article
        className={classList('QueueRow', index === this.focus && 'is-focused', this.declining === id && 'is-declining', busy && 'is-busy')}
        onclick={() => (this.focus = index)}
      >
        <div className="QueueRow-main">
          {this.tab === 'pending' ? (
            <input
              type="checkbox"
              className="QueueRow-check"
              aria-label={tx('queue.select', { name: author.name || '' })}
              checked={this.selected.has(id)}
              onchange={(e: Event) => {
                if ((e.target as HTMLInputElement).checked) this.selected.add(id);
                else this.selected.delete(id);
              }}
            />
          ) : null}
          <div className="QueueRow-content">
            <div className="QueueRow-who">
              <Avatar row={row} size={38} />
              <div>
                <strong>{author.name}</strong> <span className="QueueRow-when">· {humanTime(row.createdAt()!)}</span>
                <div className="QueueRow-meta">{meta.map((part, i) => [i ? ' · ' : '', part])}</div>
              </div>
            </div>
            <div className="QueueRow-tags">
              <Stars value={row.rating()} />
              <SubjectChip row={row} />
              <span className="TestimonialChip">{t(`queue.shows.${row.byline()}`)}</span>
              {row.source() === 'depot' ? <span className="TestimonialChip">{t('queue.from_depot')}</span> : null}
              {row.editedByStaffAt() ? <span className="TestimonialChip is-warn">{t('queue.edited_by_staff')}</span> : null}
            </div>
            {row.headline() ? <h3 className="TestimonialCard-headline">{row.headline()}</h3> : null}
            <p className="TestimonialCard-body">{row.body()}</p>
            {(row.aspects() || []).length ? (
              <ul className="QueueRow-aspects">
                {row.aspects().map((a) => {
                  const name = (app.forum.attribute<Array<{ id: number; name: string }>>('testimonialsAspects') || []).find(
                    (x) => x.id === a.id
                  )?.name;
                  return name ? (
                    <li>
                      {name} <Stars value={a.rating} />
                    </li>
                  ) : null;
                })}
              </ul>
            ) : null}
            <p className="QueueRow-consent">
              {t('queue.consent_forum')} · {row.consentExternal() ? t('queue.consent_external_yes') : t('queue.consent_external_no')}
            </p>
            {row.declineReason() ? <p className="QueueRow-reason">{t('queue.reason', { reason: row.declineReason() })}</p> : null}
          </div>
          <div className="QueueRow-actions">{this.actions(row)}</div>
        </div>
        {this.declining === id ? this.declineForm(row) : null}
      </article>
    );
  }

  actions(row: Testimonial): Mithril.Children {
    const id = row.id()!;
    const status = row.status();
    const done = () => changed();
    const edit = row.canEditWording() ? (
      <Button className="Button" onclick={() => openShare({ testimonial: row })}>
        {t('queue.edit')}
      </Button>
    ) : null;

    if (status === 'pending' || status === 'declined' || status === 'spam') {
      return [
        <Button className="Button Button--primary" onclick={() => this.act(row, 'approve').then(done)}>
          {t('queue.approve')}
        </Button>,
        <Button className="Button" icon="fas fa-star" onclick={() => this.act(row, 'feature').then(done)}>
          {t('queue.approve_feature')}
        </Button>,
        edit,
        status !== 'declined' ? (
          <Button className="Button QueueRow-decline" onclick={() => (this.declining = this.declining === id ? null : id)}>
            {t('queue.decline')}
          </Button>
        ) : null,
        status !== 'spam' ? (
          <Button className="Button Button--link" onclick={() => this.act(row, 'spam').then(done)}>
            {t('queue.spam')}
          </Button>
        ) : null,
      ];
    }
    if (status === 'approved') {
      return [
        row.isFeatured() ? (
          <Button className="Button" icon="far fa-star" onclick={() => this.act(row, 'unfeature').then(done)}>
            {t('controls.unfeature')}
          </Button>
        ) : (
          <Button className="Button" icon="fas fa-star" onclick={() => this.act(row, 'feature').then(done)}>
            {t('controls.feature')}
          </Button>
        ),
        this.tab === 'featured' ? (
          <span className="QueueRow-order">
            <Button className="Button Button--icon" icon="fas fa-arrow-up" aria-label={tx('controls.move_up')} onclick={() => this.act(row, 'up')} />
            <Button
              className="Button Button--icon"
              icon="fas fa-arrow-down"
              aria-label={tx('controls.move_down')}
              onclick={() => this.act(row, 'down')}
            />
          </span>
        ) : null,
        edit,
        <Button className="Button QueueRow-decline" onclick={() => (this.declining = this.declining === id ? null : id)}>
          {t('queue.decline')}
        </Button>,
      ];
    }
    if (status === 'removed') {
      return (
        <Button className="Button" onclick={() => this.act(row, 'restore').then(done)}>
          {t('controls.restore')}
        </Button>
      );
    }
    return null;
  }

  declineForm(row: Testimonial): Mithril.Children {
    return (
      <div className="QueueRow-declineForm">
        <p className="QueueRow-declineTitle">
          {t('queue.why')} <span className="ShareTestimonial-optional">{t('queue.why_help')}</span>
        </p>
        <div className="QueueRow-reasons" role="radiogroup">
          {REASONS.map((reason) => (
            <label className={classList('QueueRow-reasonOption', this.reason === reason && 'is-on')}>
              <input type="radio" name="decline-reason" checked={this.reason === reason} onchange={() => (this.reason = reason)} />
              {t(`queue.reasons.${reason}`)}
            </label>
          ))}
        </div>
        <textarea
          className="FormControl"
          rows={2}
          aria-label={tx('queue.note')}
          placeholder={tx('queue.note')}
          value={this.note}
          oninput={(e: InputEvent) => (this.note = (e.target as HTMLTextAreaElement).value)}
        />
        <div className="QueueRow-declineActions">
          <Button className="Button" onclick={() => (this.declining = null)}>
            {app.translator.trans('core.lib.modal.cancel_button')}
          </Button>
          <Button
            className="Button Button--danger"
            onclick={() => {
              const base = this.reason === 'other' ? '' : tx(`queue.reasons.${this.reason}_text`);
              const reason = [base, this.note.trim()].filter(Boolean).join(' ');
              this.note = '';
              this.act(row, 'decline', { reason }).then(changed);
            }}
          >
            {t('queue.decline_send')}
          </Button>
        </div>
      </div>
    );
  }
}
