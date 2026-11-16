import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { alertError, changed, moderate, t, tx } from '../util';
import { openShare } from '../share';

interface Attrs {
  row: Testimonial;
}

/**
 * The ⋯ menu on a card: what this viewer may do to it (feature, order, edit, decline, take down).
 *
 * Its own small menu rather than core's Dropdown: that one closes through Bootstrap and, where the
 * browser has it, a CloseWatcher, and in Safari the menu closed before a click on an item landed,
 * so nothing happened. Here an item runs on its own click, then the menu closes.
 */
export default class Controls extends Component<Attrs> {
  open = false;
  /** Which item gets focus once the open menu is drawn (opened from the keyboard). */
  private focusNext: 'first' | 'last' | null = null;
  private menuId = `TestimonialControls-${Math.random().toString(36).slice(2, 9)}`;
  private outside = (e: Event) => {
    if (this.open && this.element && !this.element.contains(e.target as Node)) this.close();
  };

  private frame = 0;
  private moved = () => {
    if (!this.open || this.frame) return;
    this.frame = requestAnimationFrame(() => {
      this.frame = 0;
      if (this.open) this.place();
    });
  };

  // Safari doesn't focus a button on click, so after a mouse click Escape goes to the page, not here.
  private escape = (e: KeyboardEvent) => {
    if (this.open && e.key === 'Escape' && !e.defaultPrevented) {
      e.preventDefault();
      this.close();
    }
  };

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    document.addEventListener('pointerdown', this.outside, true);
    document.addEventListener('keydown', this.escape);
    window.addEventListener('scroll', this.moved, { capture: true, passive: true });
    window.addEventListener('resize', this.moved);
  }

  onupdate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onupdate(vnode);
    if (this.open) this.place();
    if (this.open && this.focusNext) {
      const items = this.menuItems();
      (this.focusNext === 'first' ? items[0] : items[items.length - 1])?.focus();
      this.focusNext = null;
    }
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    document.removeEventListener('pointerdown', this.outside, true);
    document.removeEventListener('keydown', this.escape);
    window.removeEventListener('scroll', this.moved, { capture: true } as EventListenerOptions);
    window.removeEventListener('resize', this.moved);
    cancelAnimationFrame(this.frame);
  }

  /**
   * Pins the open menu to the window on wider screens. The testimonials page lays its cards out in
   * CSS columns, and Safari splits a menu that hangs below a card near the foot of a column into
   * the next column, out of reach. It opens below the button, or above it when there's no room.
   * Phones keep core's bottom sheet, which is fixed already. It follows the button on scroll and resize.
   */
  private place(): void {
    const menu = this.element?.querySelector<HTMLElement>('.TestimonialControls-menu');
    const toggle = this.toggleButton();
    if (!menu || !toggle || !window.matchMedia('(min-width: 768px)').matches) return;
    const r = toggle.getBoundingClientRect();
    const height = menu.offsetHeight;
    const below = r.bottom + 4;
    const above = r.top - height - 4;
    Object.assign(menu.style, {
      position: 'fixed',
      left: 'auto',
      bottom: 'auto',
      margin: '0',
      right: `${Math.max(8, window.innerWidth - r.right)}px`,
      top: `${below + height > window.innerHeight - 8 && above > 8 ? above : below}px`,
    });
  }

  private menuItems(): HTMLElement[] {
    return Array.from(this.element?.querySelectorAll<HTMLElement>('.TestimonialControls-menu [role="menuitem"]') || []);
  }

  private toggleButton(): HTMLElement | null {
    return this.element?.querySelector<HTMLElement>('.TestimonialControls-toggle') || null;
  }

  close(): void {
    this.open = false;
    m.redraw();
  }

  /** Menu-button keys: arrows/Home/End move through the items, Escape closes back to the button, Tab closes. */
  onkeydown(e: KeyboardEvent): void {
    const onToggle = e.target === this.toggleButton();
    if (!this.open) {
      if (onToggle && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        e.preventDefault();
        this.open = true;
        this.focusNext = e.key === 'ArrowDown' ? 'first' : 'last';
        m.redraw();
      }
      return;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      this.close();
      this.toggleButton()?.focus();
      return;
    }
    if (e.key === 'Tab') {
      this.close();
      return;
    }
    const items = this.menuItems();
    if (!items.length) return;
    const at = items.indexOf(document.activeElement as HTMLElement);
    let next = -1;
    if (e.key === 'ArrowDown') next = at < 0 ? 0 : (at + 1) % items.length;
    else if (e.key === 'ArrowUp') next = at < 0 ? items.length - 1 : (at - 1 + items.length) % items.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = items.length - 1;
    if (next >= 0) {
      e.preventDefault();
      items[next].focus();
    }
  }

  /** Runs an item: closes the menu first, so the card can change under it without a stale menu. */
  run(fn: () => unknown): void {
    this.open = false;
    // Focus goes back to the ⋯ button, not to the page (the item it was on is gone).
    this.toggleButton()?.focus();
    fn();
    m.redraw();
  }

  items(): Mithril.Children[] {
    const row = this.attrs.row;
    // moderate() shows the error and refreshes everything on success.
    const act =
      (action: string, extra: Record<string, unknown> = {}) =>
      () =>
        moderate(row, action, extra).catch(() => {});
    const items: Mithril.Children[] = [];
    const item = (icon: string, label: Mithril.Children, fn: () => unknown) => (
      <li role="none">
        <Button className="TestimonialControls-item" role="menuitem" icon={icon} onclick={() => this.run(fn)}>
          {label}
        </Button>
      </li>
    );

    if (row.canModerate() && row.status() === 'approved') {
      items.push(
        row.isFeatured() ? item('far fa-star', t('controls.unfeature'), act('unfeature')) : item('fas fa-star', t('controls.feature'), act('feature'))
      );
      if (row.isFeatured()) {
        items.push(item('fas fa-arrow-up', t('controls.move_up'), act('up')), item('fas fa-arrow-down', t('controls.move_down'), act('down')));
      }
    }
    if (row.canEdit() || row.canEditWording()) {
      items.push(item('fas fa-pen', row.canEdit() ? t('controls.edit') : t('controls.edit_wording'), () => openShare({ testimonial: row })));
    }
    if (row.canHide()) {
      items.push(
        item(
          row.hiddenByAuthor() ? 'fas fa-eye' : 'fas fa-eye-slash',
          row.hiddenByAuthor() ? t('controls.show_depot') : t('controls.hide_depot'),
          () =>
            (row as any)
              .save({ hiddenByAuthor: !row.hiddenByAuthor() }, { errorHandler: alertError })
              .then(() => changed())
              .catch(() => {})
              .finally(() => m.redraw())
        )
      );
    }
    if (row.canModerate() && row.status() !== 'declined' && row.status() !== 'removed') {
      items.push(
        item('fas fa-xmark', t('controls.decline'), () => {
          const reason = window.prompt(tx('controls.decline_prompt'));
          if (reason !== null) act('decline', { reason })();
        })
      );
    }
    if (row.canRemove()) {
      items.push(
        item('fas fa-trash-can', t('controls.remove'), () => {
          if (window.confirm(tx('controls.remove_confirm'))) act('remove')();
        })
      );
    }
    if (row.canRestore()) items.push(item('fas fa-rotate-left', t('controls.restore'), act('restore')));
    return items;
  }

  view() {
    const items = this.items();
    if (!items.length) return null;
    const label = tx('controls.label');
    return (
      <div
        className={classList('ButtonGroup Dropdown TestimonialControls', this.open && 'open')}
        onkeydown={(e: KeyboardEvent) => {
          this.onkeydown(e);
        }}
      >
        <button
          type="button"
          className="TestimonialControls-toggle Button Button--icon Button--flat"
          aria-haspopup="menu"
          aria-expanded={this.open ? 'true' : 'false'}
          aria-controls={this.menuId}
          aria-label={label}
          title={label}
          onclick={(e: MouseEvent) => {
            this.open = !this.open;
            // Enter or Space (a click with no pointer) puts focus on the first item.
            if (this.open && e.detail === 0) this.focusNext = 'first';
          }}
        >
          <Icon name="fas fa-ellipsis-h" className="Button-icon" />
        </button>
        {this.open ? (
          <ul id={this.menuId} className="Dropdown-menu Dropdown-menu--right TestimonialControls-menu" role="menu" aria-label={label}>
            {items}
          </ul>
        ) : null}
      </div>
    );
  }
}
