/*
 * The one lazy chunk: the page, the queue, the profile tab and the form. One entry on purpose:
 * two chunks sharing a module would make webpack emit a numbered shared chunk that jsDirectory
 * doesn't publish.
 */
export { loadCore } from './core';
export { default as TestimonialsPage } from './TestimonialsPage';
export { default as QueuePage } from './QueuePage';
export { default as ShareModal } from './ShareModal';
export { userTestimonialsPage } from './UserTestimonials';
