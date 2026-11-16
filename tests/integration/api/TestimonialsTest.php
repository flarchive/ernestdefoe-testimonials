<?php

namespace ErnestDefoe\Testimonials\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class TestimonialsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const QUOTE = 'I came for one question about a router and stayed four years. People here answer like they want you to get it working.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-testimonials');

        $joined = Carbon::parse('2021-03-04');
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $joined],
                ['id' => 4, 'username' => 'secretlee', 'email' => 'lee@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $joined],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $joined],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
            'testimonials' => [
                $this->row(1, 3, ['rating' => 5, 'is_featured' => true, 'featured_order' => 1]),
                $this->row(2, 4, ['byline' => 'anonymous', 'rating' => 4]),
                $this->row(3, 4, ['status' => 'pending', 'rating' => 2, 'byline' => 'full']),
                $this->row(4, 3, ['status' => 'declined', 'decline_reason' => 'Too short']),
            ],
            'testimonial_ratings' => [
                ['testimonial_id' => 1, 'aspect_id' => 1, 'rating' => 5],
                ['testimonial_id' => 2, 'aspect_id' => 1, 'rating' => 3],
                ['testimonial_id' => 2, 'aspect_id' => 2, 'rating' => 4],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(int $id, int $user, array $over = []): array
    {
        return $over + [
            'id' => $id,
            'user_id' => $user,
            'source' => 'native',
            'subject_type' => 'community',
            'subject_id' => null,
            'body' => self::QUOTE." ({$id})",
            'rating' => null,
            'byline' => 'full',
            'consent_at' => Carbon::now(),
            'consent_external' => false,
            'status' => 'approved',
            'approved_at' => ($over['status'] ?? 'approved') === 'approved' ? Carbon::now() : null,
            'is_featured' => false,
            'featured_order' => 0,
            'hidden_by_author' => false,
            'created_at' => Carbon::now()->subMinutes(100 - $id),
            'updated_at' => Carbon::now(),
        ];
    }

    /** @return array{int, array<string, mixed>|null, string} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        if ($method !== 'GET') {
            if (in_array($method, ['POST', 'PATCH'], true) && preg_match('#^/api/testimonials(?:/(\d+))?$#', $route, $m)) {
                $body = ['data' => ['type' => 'testimonials'] + (isset($m[1]) ? ['id' => $m[1]] : []) + ['attributes' => $body]];
            }
            $options['json'] = $body;
        }
        parse_str($query, $params);
        $response = $this->send($this->request($method, $route, $options)->withQueryParams($params));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** @return list<int> */
    private function ids(?array $json): array
    {
        return array_map(fn ($r) => (int) $r['id'], $json['data'] ?? []);
    }

    #[Test]
    public function guests_see_only_live_testimonials(): void
    {
        [$status, $json] = $this->call('GET', '/api/testimonials', null);

        $this->assertSame(200, $status);
        $this->assertEqualsCanonicalizing([1, 2], $this->ids($json));
        $this->assertArrayNotHasKey('status', $json['data'][0]['attributes']);
    }

    #[Test]
    public function an_anonymous_testimonial_names_nobody_in_the_api(): void
    {
        foreach ([null, 3] as $viewer) {
            [, , $raw] = $this->call('GET', '/api/testimonials', $viewer);
            $this->assertStringNotContainsString('secretlee', $raw);
            [, $json] = $this->call('GET', '/api/testimonials/2', $viewer);
            $author = $json['data']['attributes']['author'];
            $this->assertTrue($author['anonymous']);
            $this->assertNull($author['name']);
            $this->assertNull($author['username']);
            $this->assertNull($author['avatarUrl']);
            $this->assertArrayNotHasKey('id', $author);
            $this->assertNull($author['since'], 'not even the year they joined');
            [, , $raw] = $this->call('GET', '/api/testimonials/2', $viewer);
            $this->assertStringNotContainsString('2021', $raw);
        }

        // Its author and moderators see who wrote it.
        [, $json] = $this->call('GET', '/api/testimonials/2', 5);
        $this->assertSame('secretlee', $json['data']['attributes']['author']['name']);
        $this->assertSame(4, $json['data']['attributes']['author']['id']);
    }

    #[Test]
    public function a_profile_never_lists_what_was_posted_anonymously(): void
    {
        [, $json] = $this->call('GET', '/api/testimonials?filter[user]=4', null);
        $this->assertSame([], $this->ids($json));

        [, $json] = $this->call('GET', '/api/testimonials?filter[mine]=1', 4);
        $this->assertEqualsCanonicalizing([2, 3], $this->ids($json));
    }

    #[Test]
    public function first_name_only_sends_the_first_word_and_no_picture(): void
    {
        $this->database()->table('testimonials')->where('id', 1)->update(['byline' => 'first']);

        [, $json] = $this->call('GET', '/api/testimonials/1', null);
        // Core's display name is the username here (one word), so the first word is all of it.
        $this->assertSame('dana', $json['data']['attributes']['author']['name']);
        $this->assertNull($json['data']['attributes']['author']['username']);
        $this->assertNull($json['data']['attributes']['author']['avatarUrl']);
    }

    #[Test]
    public function a_member_writes_one_and_it_waits_for_review(): void
    {
        [$status, $json] = $this->call('POST', '/api/testimonials', 2, [
            'body' => self::QUOTE,
            'rating' => 5,
            'aspects' => [['id' => 1, 'rating' => 4], ['id' => 99, 'rating' => 5]],
            'byline' => 'first',
            'consent' => true,
            'subjectType' => 'community',
        ]);

        $this->assertSame(201, $status, json_encode($json));
        $this->assertSame('pending', $json['data']['attributes']['status']);
        $this->assertSame([['id' => 1, 'rating' => 4]], $json['data']['attributes']['aspects']);
        $id = (int) $json['data']['id'];

        [, $list] = $this->call('GET', '/api/testimonials', null);
        $this->assertNotContains($id, $this->ids($list));

        // One per member per subject.
        [$status, $json] = $this->call('POST', '/api/testimonials', 2, ['body' => self::QUOTE, 'consent' => true]);
        $this->assertSame(422, $status);
        $this->assertSame('already_written', $json['errors'][0]['code']);
    }

    #[Test]
    public function the_form_refuses_what_it_should(): void
    {
        [$status, $json] = $this->call('POST', '/api/testimonials', 2, ['body' => self::QUOTE]);
        $this->assertSame('no_consent', $json['errors'][0]['code'], (string) $status);

        [, $json] = $this->call('POST', '/api/testimonials', 2, ['body' => 'Too short.', 'consent' => true]);
        $this->assertSame('too_short', $json['errors'][0]['code']);

        [, $json] = $this->call('POST', '/api/testimonials', 2, ['body' => self::QUOTE, 'consent' => true, 'subjectType' => 'post', 'subjectId' => 999]);
        $this->assertSame('subject', $json['errors'][0]['code']);

        [$status] = $this->call('POST', '/api/testimonials', null, ['body' => self::QUOTE, 'consent' => true]);
        $this->assertContains($status, [400, 401, 403]);
    }

    #[Test]
    public function the_text_is_kept_as_plain_text(): void
    {
        [, $json] = $this->call('POST', '/api/testimonials', 2, [
            'body' => '<script>alert(1)</script><b>Bold</b> words that are long enough to pass the minimum length check here.',
            'headline' => '<img src=x onerror=alert(1)>Hello',
            'consent' => true,
        ]);
        $this->assertSame('alert(1)Bold words that are long enough to pass the minimum length check here.', $json['data']['attributes']['body']);
        $this->assertSame('Hello', $json['data']['attributes']['headline']);
    }

    #[Test]
    public function moderators_approve_decline_and_feature(): void
    {
        [$status] = $this->call('POST', '/api/testimonials/1/moderate', 3, ['action' => 'feature']);
        $this->assertSame(403, $status, 'members cannot moderate');
        [$status] = $this->call('POST', '/api/testimonials/3/moderate', 3, ['action' => 'approve']);
        $this->assertSame(404, $status, 'another member cannot see a pending one');

        [$status, $json] = $this->call('POST', '/api/testimonials/3/moderate', 5, ['action' => 'approve']);
        $this->assertSame(200, $status, json_encode($json));
        [, $list] = $this->call('GET', '/api/testimonials', null);
        $this->assertContains(3, $this->ids($list));

        [$status, $json] = $this->call('POST', '/api/testimonials/3/moderate', 5, ['action' => 'feature', 'until' => 'not a date']);
        $this->assertSame('until', $json['errors'][0]['code'] ?? null, (string) $status);
        $this->call('POST', '/api/testimonials/3/moderate', 5, ['action' => 'feature']);
        [, $featured] = $this->call('GET', '/api/testimonials?filter[featured]=1', null);
        $this->assertSame([1, 3], $this->ids($featured));
        $this->call('POST', '/api/testimonials/3/moderate', 5, ['action' => 'up']);
        [, $featured] = $this->call('GET', '/api/testimonials?filter[featured]=1', null);
        $this->assertSame([3, 1], $this->ids($featured));

        [, $json] = $this->call('POST', '/api/testimonials/3/moderate', 5, ['action' => 'decline', 'reason' => 'Names another member']);
        $this->assertSame('declined', $json['data']['attributes']['status']);
        [, $list] = $this->call('GET', '/api/testimonials', null);
        $this->assertNotContains(3, $this->ids($list));

        // The author sees why.
        [, $json] = $this->call('GET', '/api/testimonials/3', 4);
        $this->assertSame('Names another member', $json['data']['attributes']['declineReason']);

        // The queue.
        [, $queue] = $this->call('GET', '/api/testimonials?filter[status]=declined', 5);
        $this->assertEqualsCanonicalizing([3, 4], $this->ids($queue));
        // A member asking for the queue gets the live list.
        [, $notQueue] = $this->call('GET', '/api/testimonials?filter[status]=declined', 3);
        $this->assertEqualsCanonicalizing([1, 2], $this->ids($notQueue));
    }

    #[Test]
    public function the_author_takes_it_down_and_brings_it_back(): void
    {
        [$status] = $this->call('POST', '/api/testimonials/1/moderate', 4, ['action' => 'remove']);
        $this->assertSame(403, $status, 'another member cannot take it down');

        [$status, $json] = $this->call('POST', '/api/testimonials/1/moderate', 3, ['action' => 'remove']);
        $this->assertSame('removed', $json['data']['attributes']['status'], (string) $status);
        [, $list] = $this->call('GET', '/api/testimonials', null);
        $this->assertNotContains(1, $this->ids($list));

        [, $json] = $this->call('POST', '/api/testimonials/1/moderate', 3, ['action' => 'restore']);
        $this->assertSame('approved', $json['data']['attributes']['status']);
    }

    #[Test]
    public function an_edit_by_the_author_goes_back_for_review(): void
    {
        [$status, $json] = $this->call('PATCH', '/api/testimonials/1', 3, ['body' => self::QUOTE.' And then some more.']);
        $this->assertSame(200, $status, json_encode($json));
        $this->assertSame('pending', $json['data']['attributes']['status']);

        [$status] = $this->call('PATCH', '/api/testimonials/2', 3, ['body' => self::QUOTE.' Hijacked.']);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function featured_first_then_newest(): void
    {
        [$status, $json] = $this->call('GET', '/api/testimonials?filter[sort]=featured', null);

        $this->assertSame(200, $status, json_encode($json));
        // 1 is featured (and older); 2 is newer but not featured.
        $this->assertSame([1, 2], $this->ids($json));

        [, $json] = $this->call('GET', '/api/testimonials', null);
        $this->assertSame([2, 1], $this->ids($json));

        [, $json] = $this->call('GET', '/api/testimonials?filter[notFeatured]=1', null);
        $this->assertSame([2], $this->ids($json));
    }

    #[Test]
    public function the_summary_counts_live_testimonials_only(): void
    {
        [$status, $json] = $this->call('GET', '/api/testimonials-summary?subjects=1', null);

        $this->assertSame(200, $status);
        $this->assertSame(2, $json['count']);
        $this->assertSame(4.5, $json['average']);
        $this->assertSame(1, $json['spread']['5']);
        $this->assertSame(1, $json['spread']['4']);
        $this->assertSame(0, $json['spread']['2']);
        $this->assertSame([['id' => 1, 'name' => 'Customer service', 'average' => 4, 'count' => 2], ['id' => 2, 'name' => 'Quality', 'average' => 4, 'count' => 1]], $json['aspects']);
        $this->assertSame([['type' => 'community', 'id' => null, 'label' => null, 'count' => 2]], $json['subjects']);

        // A change shows up for guests at once (the cached answer is versioned).
        $this->call('POST', '/api/testimonials/3/moderate', 5, ['action' => 'approve']);
        [, $json] = $this->call('GET', '/api/testimonials-summary', null);
        $this->assertSame(3, $json['count']);
    }

    #[Test]
    public function the_forum_payload_tells_moderators_the_queue_count_and_no_one_else(): void
    {
        [, $mod] = $this->call('GET', '/api', 5);
        $this->assertSame(1, $mod['data']['attributes']['testimonialsPending']);
        $this->assertTrue($mod['data']['attributes']['testimonialsCanModerate']);

        [, $member] = $this->call('GET', '/api', 3);
        $this->assertArrayNotHasKey('testimonialsPending', $member['data']['attributes']);
        $this->assertFalse($member['data']['attributes']['testimonialsCanModerate']);
        $this->assertIsArray($member['data']['attributes']['testimonialsAspects']);

        [, $guest] = $this->call('GET', '/api', null);
        $this->assertFalse($guest['data']['attributes']['testimonialsCanCreate']);
        $this->assertArrayNotHasKey('testimonialsCreateRefusal', $guest['data']['attributes']);
    }

    #[Test]
    public function a_deleted_member_takes_their_testimonials_and_ratings(): void
    {
        [$status] = $this->call('DELETE', '/api/users/4', 1);
        $this->assertSame(204, $status);

        $this->assertSame(0, $this->database()->table('testimonials')->where('user_id', 4)->count());
        $this->assertSame(0, $this->database()->table('testimonial_ratings')->where('testimonial_id', 2)->count());
        $this->assertSame(1, $this->database()->table('testimonial_ratings')->where('testimonial_id', 1)->count(), 'other members keep theirs');
    }

    #[Test]
    public function a_testimonial_about_a_deleted_post_stays_as_a_community_one(): void
    {
        $this->database()->table('discussions')->insert(['id' => 1, 'title' => 'Router help', 'slug' => 'router-help', 'created_at' => Carbon::now(), 'user_id' => 3, 'comment_count' => 2, 'last_post_number' => 2]);
        $this->database()->table('posts')->insert([
            ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Help</p></t>'],
            ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Fixed</p></t>'],
        ]);
        $this->database()->table('discussions')->where('id', 1)->update(['first_post_id' => 1]);
        $this->database()->table('testimonials')->where('id', 1)->update(['subject_type' => 'post', 'subject_id' => 2]);
        $this->database()->table('testimonials')->where('id', 2)->update(['subject_type' => 'post', 'subject_id' => 1]);

        [$status] = $this->call('DELETE', '/api/posts/2', 1);
        $this->assertSame(204, $status);

        $row = fn (int $id) => $this->database()->table('testimonials')->where('id', $id)->first();
        $this->assertSame('community', $row(1)->subject_type);
        $this->assertNull($row(1)->subject_id);
        $this->assertSame('post', $row(2)->subject_type, 'a testimonial about a post that is still there keeps it');
    }

    #[Test]
    public function the_nudge_stops_once_a_member_has_written_one(): void
    {
        $this->setting('ernestdefoe-testimonials.nudge', '1');
        $this->setting('ernestdefoe-testimonials.nudge_posts', '0');
        $this->setting('ernestdefoe-testimonials.nudge_days', '0');

        $nudge = function (int $as): ?bool {
            [, $json] = $this->call('GET', '/api', $as);

            return $json['data']['attributes']['testimonialsNudge'] ?? null;
        };

        $this->assertTrue($nudge(2));
        $this->assertFalse($nudge(3));

        // The answer is cached; writing one forgets it, so the next page load stops nudging.
        $this->call('POST', '/api/testimonials', 2, ['body' => self::QUOTE, 'consent' => true]);
        $this->assertFalse($nudge(2));
    }

    #[Test]
    public function the_list_costs_the_same_queries_for_one_or_twenty_four(): void
    {
        $count = function (): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->call('GET', '/api/testimonials?page[limit]=24', null);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $this->database()->table('testimonials')->where('id', '>', 1)->delete();
        $this->call('GET', '/api/testimonials', null);
        $one = $count();

        $rows = [];
        for ($i = 10; $i < 34; $i++) {
            $rows[] = $this->row($i, $i % 2 ? 3 : 4, ['rating' => 4]);
        }
        $this->database()->table('testimonials')->insert($rows);
        $many = $count();

        $this->assertSame($one, $many);
    }
}
