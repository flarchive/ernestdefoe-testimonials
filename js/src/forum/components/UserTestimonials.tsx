import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type Testimonial from '../Testimonial';
import { Card } from '../widgets/ui';
import { onChange, t } from '../util';
import { openShare } from '../share';
import Controls from './Controls';

let reload: (() => void) | null = null;
onChange(() => reload?.());

interface ListAttrs extends ComponentAttrs {
  user: User;
}

/**
 * The profile tab's list. Your own: every testimonial you wrote, at any stage, with what happened
 * to it and a way to edit, take it down or bring it back (and your Depot reviews, which you can
 * take out of the widgets). Someone else's: only what they posted under their full name, so a
 * profile never gives away an anonymous or first-name testimonial.
 */
class UserTestimonialsList extends Component<ListAttrs> {
  rows: Testimonial[] = [];
  loading = true;
  more = false;

  oninit(vnode: Mithril.Vnode<ListAttrs, this>) {
    super.oninit(vnode);
    reload = () => this.load(0);
    this.load(0);
  }

  onremove(vnode: Mithril.VnodeDOM<ListAttrs, this>) {
    super.onremove(vnode);
    reload = null;
  }

  own(): boolean {
    return !!app.session.user && this.attrs.user === app.session.user;
  }

  load(offset: number) {
    this.loading = true;
    const filter = this.own() ? { mine: 1 } : { user: this.attrs.user.id() };
    app.store
      .find<Testimonial[]>('testimonials', { filter, page: { offset, limit: 24 } })
      .then((rows: any) => {
        this.rows = offset ? [...this.rows, ...rows] : [...rows];
        this.more = !!rows.payload?.links?.next;
      })
      // The request already showed its error; the list keeps what it had.
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="UserTestimonials">
        {this.own() && app.forum.attribute('testimonialsCanCreate') ? (
          <div className="UserTestimonials-head">
            <Button className="Button Button--primary" icon="fas fa-pen" onclick={() => openShare()}>
              {t('share.button')}
            </Button>
          </div>
        ) : null}
        {this.list()}
        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="TestimonialsPage-more">
            <Button className="Button" onclick={() => this.load(this.rows.length)}>
              {t('page.load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  list(): Mithril.Children {
    if (this.rows.length) {
      return (
        <div className="TestimonialsPage-grid">
          {this.rows.map((row) => (
            <Card row={row} className={classList(row.status() && `is-${row.status()}`)} footer={this.footer(row)} />
          ))}
        </div>
      );
    }
    return this.loading ? null : <p className="TestimonialsPage-empty">{this.own() ? t('profile.empty_own') : t('profile.empty')}</p>;
  }

  /** What happened to it (your own only), and its ⋯ menu. */
  footer(row: Testimonial): Mithril.Children {
    const status = row.status();
    const label = !status
      ? null
      : status === 'approved' && row.hiddenByAuthor()
        ? 'hidden'
        : status === 'approved' && row.isFeatured()
          ? 'featured'
          : status;
    return (
      <div className="UserTestimonials-foot">
        {this.own() && label ? (
          <span className={classList('UserTestimonials-status', `is-${label}`)}>
            {t(`profile.status.${label}`)}
            {status === 'declined' && row.declineReason() ? <span className="UserTestimonials-reason">{row.declineReason()}</span> : null}
            {status === 'removed' ? <span className="UserTestimonials-reason">{t('profile.removed_help')}</span> : null}
          </span>
        ) : (
          <span />
        )}
        <Controls row={row} />
      </div>
    );
  }
}

/**
 * The profile tab. UserPage lives in a lazy chunk, so the page class is made once it's loaded;
 * the list is its own component (keyed by the member, so another profile starts afresh).
 */
let made: any = null;

export async function userTestimonialsPage(): Promise<any> {
  if (made) return made;
  const mod: any = await flarum.reg.asyncModuleImport('flarum/forum/components/UserPage');
  const UserPage = mod.default || mod;

  made = class UserTestimonialsPage extends UserPage {
    oninit(vnode: Mithril.Vnode) {
      super.oninit(vnode);
      this.loadUser(m.route.param('username'));
    }

    content() {
      return this.user ? <UserTestimonialsList key={this.user.id()} user={this.user} /> : null;
    }
  };

  return made;
}
