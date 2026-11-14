import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { Card } from '../widgets/ui';
import { onChange, t } from '../util';
import { openShare } from '../share';
import Controls from './Controls';

declare const flarum: any;

let reload: (() => void) | null = null;
onChange(() => reload?.());

/**
 * The profile tab. Your own: every testimonial you wrote, at any stage, with what happened to it
 * and a way to edit, take it down or bring it back (and your Depot reviews, which you can take out
 * of the widgets). Someone else's: only what they posted under their full name, so a profile never
 * gives away an anonymous or first-name testimonial.
 *
 * UserPage lives in a lazy chunk, so the class is made once it's loaded.
 */
let made: any = null;

export async function userTestimonialsPage(): Promise<any> {
  if (made) return made;
  const mod = await flarum.reg.asyncModuleImport('flarum/forum/components/UserPage');
  const UserPage = mod.default || mod;

  made = class UserTestimonialsPage extends UserPage {
    rows: Testimonial[] = [];
    loading = true;
    more = false;

    oninit(vnode: Mithril.Vnode) {
      super.oninit(vnode);
      this.loadUser(m.route.param('username'));
      reload = () => this.load(0);
    }

    onremove(vnode: Mithril.VnodeDOM) {
      super.onremove(vnode);
      reload = null;
    }

    show(user: any) {
      super.show(user);
      this.load(0);
    }

    own(): boolean {
      return !!app.session.user && this.user === app.session.user;
    }

    load(offset: number) {
      if (!this.user) return;
      this.loading = true;
      const filter = this.own() ? { mine: 1 } : { user: this.user.id() };
      app.store
        .find<Testimonial[]>('testimonials', { filter, page: { offset, limit: 24 } })
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

    content() {
      return (
        <div className="UserTestimonials">
          {this.own() && app.forum.attribute('testimonialsCanCreate') ? (
            <div className="UserTestimonials-head">
              <Button className="Button Button--primary" icon="fas fa-pen" onclick={() => openShare()}>
                {t('share.button')}
              </Button>
            </div>
          ) : null}
          {this.rows.length ? (
            <div className="TestimonialsPage-grid">
              {this.rows.map((row) => (
                <Card row={row} className={classList(row.status() && `is-${row.status()}`)} footer={this.footer(row)} />
              ))}
            </div>
          ) : this.loading ? null : (
            <p className="TestimonialsPage-empty">{this.own() ? t('profile.empty_own') : t('profile.empty')}</p>
          )}
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

    footer(row: Testimonial): Mithril.Children {
      const status = row.status();
      const label = status
        ? status === 'approved' && row.hiddenByAuthor()
          ? 'hidden'
          : status === 'approved' && row.isFeatured()
            ? 'featured'
            : status
        : null;
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
  };

  return made;
}
