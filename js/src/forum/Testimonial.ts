import Model from 'flarum/common/Model';

export interface Author {
  name: string | null;
  avatarUrl: string | null;
  username: string | null;
  anonymous: boolean;
  since: string | null;
  // Moderators only.
  id?: number;
  commentCount?: number;
  joinedAt?: string | null;
  trustLevel?: number | null;
}

export interface Subject {
  type: 'community' | 'tag' | 'depot' | 'post';
  id: number | null;
  label: string | null;
  url: string | null;
}

/** A testimonial as TestimonialResource serializes it. The author block already follows the byline. */
export default class Testimonial extends Model {
  headline = Model.attribute<string | null>('headline');
  body = Model.attribute<string>('body');
  rating = Model.attribute<number | null>('rating');
  byline = Model.attribute<'full' | 'first' | 'anonymous'>('byline');
  source = Model.attribute<'native' | 'depot'>('source');
  isFeatured = Model.attribute<boolean>('isFeatured');
  createdAt = Model.attribute('createdAt', Model.transformDate);
  author = Model.attribute<Author>('author');
  subject = Model.attribute<Subject>('subject');
  aspects = Model.attribute<Array<{ id: number; rating: number }>>('aspects');

  status = Model.attribute<string | undefined>('status');
  declineReason = Model.attribute<string | null>('declineReason');
  consentExternal = Model.attribute<boolean | undefined>('consentExternal');
  hiddenByAuthor = Model.attribute<boolean | undefined>('hiddenByAuthor');
  editedByStaffAt = Model.attribute('editedByStaffAt', Model.transformDate);
  removedAt = Model.attribute('removedAt', Model.transformDate);

  canEdit = Model.attribute<boolean>('canEdit');
  canEditWording = Model.attribute<boolean>('canEditWording');
  canRemove = Model.attribute<boolean>('canRemove');
  canRestore = Model.attribute<boolean>('canRestore');
  canHide = Model.attribute<boolean>('canHide');
  canModerate = Model.attribute<boolean>('canModerate');
}
