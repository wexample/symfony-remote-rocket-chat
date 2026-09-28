<?php

namespace Wexample\SymfonyRemoteRocketChat\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use stdClass;
use Wexample\SymfonyDataSync\Class\FieldMapping;
use Wexample\SymfonyDataSync\Class\LinkRecord;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\Predicate;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Class\SyncRelation;
use Wexample\SymfonyDataSync\Enum\MatchNormalizer;
use Wexample\SymfonyDataSync\Enum\OrphanLocalPolicy;
use Wexample\SymfonyDataSync\Enum\PredicateOperator;
use Wexample\SymfonyDataSync\Service\Matcher;
use Wexample\SymfonyDataSync\Service\SyncPlanner;
use Wexample\SymfonyDataSync\Testing\InMemoryLinkStore;
use Wexample\SymfonyDataSync\Testing\InMemoryLocalStore;
use Wexample\SymfonyRemoteRocketChat\Class\RocketChatClient;
use Wexample\SymfonyRemoteRocketChat\Exception\RocketChatException;
use Wexample\SymfonyRemoteRocketChat\Service\RocketChatUserAdapter;

class RocketChatUserAdapterTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private MockHandler $handler;

    private RocketChatUserAdapter $adapter;

    protected function setUp(): void
    {
        $this->handler = new MockHandler();
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $this->adapter = new RocketChatUserAdapter(new RocketChatClient(
            'https://chat.example.test',
            'token',
            new GuzzleClient(['base_uri' => 'https://chat.example.test/', 'handler' => $stack]),
            [RocketChatClient::HEADER_USER_ID => 'bot'],
        ));
    }

    public function testUsersBecomeFlatRemoteItems(): void
    {
        $this->handler->append($this->answer(['users' => [$this->user('u1', 'ada', 'ada@example.test', ['user'])], 'total' => 1]));

        $items = iterator_to_array($this->adapter->list(), false);

        $this->assertSame('u1', $items[0]->id);
        $this->assertSame([
            'username' => 'ada',
            'email' => 'ada@example.test',
            'name' => 'Ada',
            'roles' => ['user'],
            'active' => true,
        ], $items[0]->fields);
    }

    public function testAnUnknownIdIsAbsentButAnOutageRaises(): void
    {
        $this->handler->append(new Response(400, [], json_encode(['success' => false, 'error' => 'User not found.'])));
        $this->assertNull($this->adapter->get('missing'));

        $this->handler->append(new Response(503));
        $this->expectException(RocketChatException::class);
        $this->adapter->get('u1');
    }

    public function testUsersAreFoundByEmailAndByUsername(): void
    {
        $this->handler->append(
            $this->answer(['users' => [$this->user('u1', 'ada', 'ada@example.test')]]),
            $this->answer(['user' => $this->user('u2', 'bob', 'bob@example.test')]),
        );

        $byEmail = iterator_to_array($this->adapter->findBy('email', ' ADA@example.test'), false);
        $byUsername = iterator_to_array($this->adapter->findBy('username', 'bob'), false);

        $this->assertSame('u1', $byEmail[0]->id);
        $this->assertSame(['emails.address' => 'ada@example.test'], json_decode($this->queryOf(0)['query'], true));
        $this->assertSame('u2', $byUsername[0]->id);
    }

    public function testACreatedUserGetsARandomPasswordToChange(): void
    {
        $this->handler->append($this->answer(['user' => $this->user('u9', 'cid', 'cid@example.test')]));

        $item = $this->adapter->create(['username' => 'cid', 'email' => 'cid@example.test', 'name' => 'Cid']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('u9', $item->id);
        $this->assertSame(32, strlen($body['password']));
        $this->assertTrue($body['requirePasswordChange']);
    }

    public function testDisablingDeactivatesTheAccount(): void
    {
        $this->handler->append($this->answer(['user' => $this->user('u1', 'ada', 'ada@example.test')]));

        $this->adapter->disable('u1');

        $this->assertSame('/api/v1/users.setActiveStatus', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['userId' => 'u1', 'activeStatus' => false], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testAUserDefinitionPlansTheLegacyCases(): void
    {
        // Ada matches by email (other username), Bob has no account, a stale
        // id points nowhere, and the bot is protected.
        $this->handler->append($this->answer(['users' => [
            $this->user('rc-ada', 'ada_l', 'ADA@example.test'),
            $this->user('rc-cat', 'rocket.cat', 'cat@example.test', ['bot']),
        ], 'total' => 2]));
        $locals = new InMemoryLocalStore([
            'ada' => ['username' => 'ada', 'email' => 'ada@example.test'],
            'bob' => ['username' => 'bob', 'email' => 'bob@example.test'],
            'cid' => ['username' => 'cid', 'email' => 'cid@example.test'],
        ]);
        $links = (new InMemoryLinkStore())->seed('chat', new LinkRecord('cid', 'rc-deleted'));

        $plan = (new SyncPlanner(new Matcher()))->plan(new SyncDefinition(
            'chat',
            stdClass::class,
            $this->adapter,
            $locals,
            $links,
            matchRules: [
                new ExactFieldRule('email', 'email', [MatchNormalizer::Email]),
                new ExactFieldRule('username', 'username', [MatchNormalizer::Lower]),
            ],
            fields: [new FieldMapping('username', 'username'), new FieldMapping('email', 'email')],
            remoteExclude: [new Predicate('roles', PredicateOperator::Contains, 'bot')],
            orphanLocal: OrphanLocalPolicy::CreateRemote,
        ));

        $this->assertSame(
            ['cid' => 'local_unlink', 'ada' => 'local_link', 'bob' => 'remote_create'],
            array_column(array_map(static fn (SyncRelation $relation): array => [
                'local' => $relation->local?->id ?? $relation->link->localId,
                'operation' => $relation->operation->value,
            ], $plan->relations), 'operation', 'local')
        );
        $this->assertCount(1, $this->history);
    }

    private function answer(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data + ['success' => true]));
    }

    private function user(string $id, string $username, string $email, array $roles = ['user']): array
    {
        return ['_id' => $id, 'username' => $username, 'emails' => [['address' => $email]], 'name' => ucfirst($username), 'roles' => $roles, 'active' => true];
    }

    private function queryOf(int $index): array
    {
        parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);

        return $query;
    }
}
