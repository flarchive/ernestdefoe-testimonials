import app from 'flarum/forum/app';
import Dropdown from 'flarum/common/components/Dropdown';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type Testimonial from '../Testimonial';
import { moderate, t, tx } from '../util';
import { openShare } from '../share';

/** The ⋯ menu on a card: what this viewer may do to it (feature, order, edit, decline, take down). */
function ControlsView(attrs: { row: Testimonial; onchange?: () => void }): Mithril.Children {
  const row = attrs.row;
  const done = () => attrs.onchange?.();
  const items: Mithril.Children[] = [];

  if (row.canModerate()) {
    if (row.status() === 'approved') {
      items.push(
        row.isFeatured() ? (
          <Button icon="far fa-star" onclick={() => moderate(row, 'unfeature').then(done)}>
            {t('controls.unfeature')}
          </Button>
        ) : (
          <Button icon="fas fa-star" onclick={() => moderate(row, 'feature').then(done)}>
            {t('controls.feature')}
          </Button>
        )
      );
      if (row.isFeatured()) {
        items.push(
          <Button icon="fas fa-arrow-up" onclick={() => moderate(row, 'up').then(done)}>
            {t('controls.move_up')}
          </Button>,
          <Button icon="fas fa-arrow-down" onclick={() => moderate(row, 'down').then(done)}>
            {t('controls.move_down')}
          </Button>
        );
      }
    }
  }
  if (row.canEdit() || row.canEditWording()) {
    items.push(
      <Button icon="fas fa-pen" onclick={() => openShare({ testimonial: row })}>
        {row.canEdit() ? t('controls.edit') : t('controls.edit_wording')}
      </Button>
    );
  }
  if (row.canHide()) {
    items.push(
      <Button
        icon={row.hiddenByAuthor() ? 'fas fa-eye' : 'fas fa-eye-slash'}
        onclick={() =>
          (row as any).save({ hiddenByAuthor: !row.hiddenByAuthor() }).then(() => {
            done();
            m.redraw();
          })
        }
      >
        {row.hiddenByAuthor() ? t('controls.show_depot') : t('controls.hide_depot')}
      </Button>
    );
  }
  if (row.canModerate() && row.status() !== 'declined' && row.status() !== 'removed') {
    items.push(
      <Button
        icon="fas fa-xmark"
        onclick={() => {
          const reason = window.prompt(tx('controls.decline_prompt'));
          if (reason !== null) moderate(row, 'decline', { reason }).then(done);
        }}
      >
        {t('controls.decline')}
      </Button>
    );
  }
  if (row.canRemove()) {
    items.push(
      <Button
        icon="fas fa-trash-can"
        onclick={() => {
          if (window.confirm(tx('controls.remove_confirm'))) moderate(row, 'remove').then(done);
        }}
      >
        {t('controls.remove')}
      </Button>
    );
  }
  if (row.canRestore()) {
    items.push(
      <Button icon="fas fa-rotate-left" onclick={() => moderate(row, 'restore').then(done)}>
        {t('controls.restore')}
      </Button>
    );
  }

  if (!items.length) return null;
  return (
    <Dropdown
      className="TestimonialControls"
      buttonClassName="Button Button--icon Button--flat"
      menuClassName="Dropdown-menu--right"
      icon="fas fa-ellipsis-h"
      label={tx('controls.label')}
      accessibleToggleLabel={tx('controls.label')}
    >
      {items}
    </Dropdown>
  );
}

const Controls: Mithril.Component<{ row: Testimonial; onchange?: () => void }> = { view: (vnode) => ControlsView(vnode.attrs) };
export default Controls;
