import app from 'flarum/forum/app';
import Extend from 'flarum/common/extenders';
import Notification from 'flarum/forum/components/Notification';
import Testimonial from './Testimonial';
import { ui } from './share';
import { t } from './util';

/** The model, the three pages (one lazy chunk) and the four notifications. */
export const NOTIFICATIONS: Record<string, string> = {
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

const page = (pick: (chunk: any) => any) => () => ui().then(async (chunk) => (await chunk.loadCore(), { default: pick(chunk) }));

export default [
  new Extend.Store().add('testimonials', Testimonial),
  new Extend.Routes()
    .add(
      'testimonials',
      '/testimonials',
      page((chunk) => chunk.TestimonialsPage)
    )
    .add(
      'testimonials.queue',
      '/testimonials/queue',
      page((chunk) => chunk.QueuePage)
    )
    .add('user.testimonials', '/u/:username/testimonials', () => ui().then(async (chunk) => ({ default: await chunk.userTestimonialsPage() }))),
  Object.keys(NOTIFICATIONS).reduce((ext, type) => ext.add(type, notificationComponent(type)), new Extend.Notification()),
];
