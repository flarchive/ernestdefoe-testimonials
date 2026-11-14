import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import stringToColor from 'flarum/common/utils/stringToColor';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { t } from '../util';

/*
 * The pieces every widget and page draws a testimonial with. Colours come from Flarum's CSS
 * properties (less/forum.less), so themes and dark mode carry through.
 */

function StarsView(attrs: { value: number | null; size?: 'sm' | 'md' | 'lg' }): Mithril.Children {
  const value = attrs.value ?? 0;
  if (!value) return null;
  const full = Math.round(value * 2) / 2;
  return (
    <span
      className={classList('TestimonialStars', `TestimonialStars--${attrs.size || 'sm'}`)}
      role="img"
      aria-label={t('stars_label', { rating: value })}
    >
      {[1, 2, 3, 4, 5].map((i) => (
        <span className={classList('TestimonialStars-star', i <= full ? 'is-on' : i - 0.5 === full ? 'is-half' : '')} aria-hidden="true">
          ★
        </span>
      ))}
    </span>
  );
}

function AvatarView(attrs: { row: Testimonial; size?: number }): Mithril.Children {
  const author = attrs.row.author();
  const size = attrs.size || 36;
  const style = { width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.38)}px` };
  if (author.anonymous) {
    return (
      <span className="TestimonialAvatar TestimonialAvatar--anonymous" style={style} aria-hidden="true">
        <Icon name="fas fa-user" />
      </span>
    );
  }
  if (author.avatarUrl) return <img className="TestimonialAvatar" src={author.avatarUrl} alt="" loading="lazy" style={style} />;
  const name = author.name || '?';
  return (
    <span className="TestimonialAvatar" style={{ ...style, '--avatar-bg': `#${stringToColor(name)}` }} aria-hidden="true">
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

function BylineView(attrs: { row: Testimonial; size?: number; withSubject?: boolean }): Mithril.Children {
  const row = attrs.row;
  const author = row.author();
  const name = author.anonymous ? t('byline.anonymous') : author.name;
  const subject = row.subject();
  return (
    <div className="TestimonialByline">
      <Avatar row={row} size={attrs.size} />
      <div className="TestimonialByline-text">
        {author.username ? (
          <Link className="TestimonialByline-name" href={app.route('user', { username: author.username })}>
            {name}
          </Link>
        ) : (
          <span className="TestimonialByline-name">{name}</span>
        )}
        <span className="TestimonialByline-meta">
          {author.since ? t('byline.since', { year: author.since }) : null}
          {attrs.withSubject && subject.label ? [author.since ? ' · ' : '', subject.label] : null}
        </span>
      </div>
    </div>
  );
}

/** What it's about, as a small chip (a link where there is one). */
function SubjectChipView(attrs: { row: Testimonial }): Mithril.Children {
  const subject = attrs.row.subject();
  const label = subject.type === 'community' ? t('subject.community') : subject.label;
  if (!label) return null;
  return subject.url ? (
    <a className="TestimonialChip" href={subject.url}>
      {label}
    </a>
  ) : (
    <span className="TestimonialChip">{label}</span>
  );
}

/** A testimonial as a card: the wall, the page and the queue all use it. */
function CardView(attrs: {
  row: Testimonial;
  showRating?: boolean;
  large?: boolean;
  footer?: Mithril.Children;
  className?: string;
}): Mithril.Children {
  const row = attrs.row;
  return (
    <article className={classList('TestimonialCard', attrs.large && 'TestimonialCard--large', row.isFeatured() && 'is-featured', attrs.className)}>
      <div className="TestimonialCard-top">
        {attrs.showRating !== false ? <Stars value={row.rating()} /> : null}
        <SubjectChip row={row} />
      </div>
      {attrs.large ? <QuoteMark /> : null}
      {row.headline() ? <h3 className="TestimonialCard-headline">{row.headline()}</h3> : null}
      <p className="TestimonialCard-body">{row.body()}</p>
      <Byline row={row} size={attrs.large ? 40 : 32} />
      {attrs.footer}
    </article>
  );
}

function QuoteMarkView(_attrs: Record<string, never>): Mithril.Children {
  return (
    <svg className="TestimonialQuoteMark" width="28" height="22" viewBox="0 0 28 22" aria-hidden="true">
      <path d="M0 22V13.2C0 5.6 4 1.2 11 0l1 3.4C8 4.6 6.3 7 6.2 10.4H11V22zm16 0V13.2C16 5.6 20 1.2 27 0l1 3.4c-4 1.2-5.7 3.6-5.8 7H27V22z" />
    </svg>
  );
}

/** A plain function as a Mithril component (a bare function in JSX is a closure component factory). */
function component<A>(view: (attrs: A) => Mithril.Children): Mithril.Component<A> {
  return { view: (vnode) => view(vnode.attrs) };
}

export const Stars = component(StarsView);
export const Avatar = component(AvatarView);
export const Byline = component(BylineView);
export const SubjectChip = component(SubjectChipView);
export const Card = component(CardView);
export const QuoteMark = component(QuoteMarkView);

/*
 * Narrow spots (sidebars, a third-width column, FoF's side sections) get the compact look,
 * decided by the widget's own width: one ResizeObserver for every widget on the page.
 */
let observer: ResizeObserver | null = null;
export function watchWidth(el: Element): void {
  if (typeof ResizeObserver === 'undefined') return;
  observer ??= new ResizeObserver((entries) => {
    for (const entry of entries) {
      const width = entry.contentRect.width;
      entry.target.classList.toggle('is-compact', width > 0 && width < 520);
      entry.target.classList.toggle('is-narrow', width > 0 && width < 320);
    }
  });
  observer.observe(el);
}
export function unwatchWidth(el: Element): void {
  observer?.unobserve(el);
}
