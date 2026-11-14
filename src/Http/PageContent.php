<?php

namespace ErnestDefoe\Testimonials\Http;

use ErnestDefoe\Testimonials\Summary;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /testimonials as the server sends it: a title, canonical URL and a description with the count
 * and average only, never a member's name (crawlers and link previews read this). The summary
 * is the guests' cached one.
 */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly Summary $summary,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-testimonials.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('testimonials');

        $summary = $this->summary->for(RequestUtil::getActor($request), null, null);
        $document->meta['description'] = (string) ($summary['average'] !== null
            ? $this->translator->trans('ernestdefoe-testimonials.forum.page.description_rated', ['{count}' => $summary['count'], '{average}' => $summary['average']])
            : $this->translator->trans('ernestdefoe-testimonials.forum.page.description'));

        return $document;
    }
}
