<?php

namespace ErnestDefoe\Testimonials\Api;

use ErnestDefoe\Testimonials\Moderation;
use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Api\JsonApi;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/testimonials/{id}/moderate {action, reason?, until?}
 *
 * approve | feature | unfeature | decline | spam | up | down (moderators), remove | restore (the
 * author or a moderator). Answers with the testimonial as TestimonialResource shows it, so the
 * store updates like any other save.
 */
class ModerateController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Moderation $moderation,
        private readonly JsonApi $api,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id = (int) Arr::get((array) $request->getAttribute('routeParameters'), 'id');
        $row = Testimonial::query()->whereVisibleTo($actor)->findOrFail($id);
        $body = (array) $request->getParsedBody();

        $this->moderation->act($row, (string) ($body['action'] ?? ''), $body, $actor);

        return $this->api
            ->forResource(TestimonialResource::class)
            ->forEndpoint('show')
            ->handle($request->withMethod('GET')->withQueryParams(['id' => (string) $row->id])->withParsedBody(null));
    }
}
