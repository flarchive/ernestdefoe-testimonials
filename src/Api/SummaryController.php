<?php

namespace ErnestDefoe\Testimonials\Api;

use ErnestDefoe\Testimonials\Subjects;
use ErnestDefoe\Testimonials\Summary;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/testimonials-summary?subject=community|tag|depot|post&subjectId=N (or &slug=…)
 *
 * The rating summary (Summary): count, average, 5→1 spread and each aspect's average, over the
 * live testimonials this viewer may see.
 */
class SummaryController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Summary $summary,
        private readonly Subjects $subjects,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $id = Arr::get($query, 'subjectId');
        if (! is_numeric($id) && ($slug = Arr::get($query, 'slug'))) {
            $id = $this->subjects->idForSlug((string) Arr::get($query, 'subject'), (string) $slug) ?? 0;
        }

        return new JsonResponse($this->summary->for(
            RequestUtil::getActor($request),
            Arr::get($query, 'subject') ? (string) Arr::get($query, 'subject') : null,
            is_numeric($id) ? (int) $id : null,
            (bool) Arr::get($query, 'subjects'),
        ));
    }
}
