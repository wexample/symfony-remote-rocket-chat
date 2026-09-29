<?php

namespace Wexample\SymfonyRemoteRocketChat\Tests\Unit\Class;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Wexample\PhpApi\Common\ClientOptions;
use Wexample\PhpRemote\Class\ApiClientFactory;
use Wexample\PhpRemote\Class\ApiClientRemote;
use Wexample\PhpRemote\Class\ClientDefinition;
use Wexample\PhpRemote\Class\RemoteRegistry;
use Wexample\PhpRemote\Enum\RemoteState;
use Wexample\SymfonyRemoteRocketChat\Class\RocketChatClient;
use Wexample\SymfonyRemoteRocketChat\Exception\RocketChatException;

class RocketChatClientTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private MockHandler $handler;

    protected function setUp(): void
    {
        $this->history = [];
        $this->handler = new MockHandler();
    }

    public function testTheTokenTravelsInRocketChatHeadersNotAsBearer(): void
    {
        $this->handler->append($this->answer(['_id' => 'bot', 'username' => 'wexbot']));

        $this->assertSame('wexbot', $this->client()->me()['username']);

        $request = $this->lastRequest();
        $this->assertSame('/api/v1/me', $request->getUri()->getPath());
        $this->assertSame('token', $request->getHeaderLine(RocketChatClient::HEADER_AUTH_TOKEN));
        $this->assertSame('bot', $request->getHeaderLine(RocketChatClient::HEADER_USER_ID));
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function testListingFollowsPagesUntilTheTotal(): void
    {
        $this->handler->append(
            $this->answer(['users' => $this->users(0, 100), 'total' => 130]),
            $this->answer(['users' => $this->users(100, 30), 'total' => 130]),
        );

        $users = iterator_to_array($this->client()->listUsers(), false);

        $this->assertCount(130, $users);
        $this->assertSame('user129', $users[129]['username']);
        $this->assertSame(['offset=0&count=100', 'offset=100&count=100'], array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getQuery(),
            $this->history
        ));
    }

    public function testListingStopsOnAnEmptyPage(): void
    {
        $this->handler->append($this->answer(['groups' => []]));

        $this->assertSame([], iterator_to_array($this->client()->listGroups(), false));
        $this->assertCount(1, $this->history);
    }

    public function testARefusalCarriesRocketChatReason(): void
    {
        $this->handler->append(new Response(400, [], json_encode(['success' => false, 'error' => 'Username is already in use'])));

        try {
            $this->client()->createUser('ada', 'ada@example.test', 'Ada', 'temporary');
            $this->fail('RocketChatException expected.');
        } catch (RocketChatException $exception) {
            $this->assertSame('Username is already in use', $exception->getMessage());
            $this->assertFalse($exception->isTransient());
        }
    }

    public function testASuccessFlagSetToFalseIsARefusalToo(): void
    {
        $this->handler->append($this->answer(['error' => 'Room not found'], false));

        $this->expectException(RocketChatException::class);
        $this->expectExceptionMessage('Room not found');

        $this->client()->archiveGroup('room');
    }

    public function testAnOutageIsTransient(): void
    {
        $this->handler->append(new Response(503));

        try {
            $this->client()->getUser('u1');
            $this->fail('RocketChatException expected.');
        } catch (RocketChatException $exception) {
            $this->assertTrue($exception->isTransient());
        }
    }

    public function testUsersAreCreatedWithAPasswordToChange(): void
    {
        $this->handler->append($this->answer(['user' => ['_id' => 'u1']]));

        $this->client()->createUser('ada', 'ada@example.test', 'Ada', 'temporary', extra: ['roles' => ['user']]);

        $this->assertSame([
            'username' => 'ada',
            'email' => 'ada@example.test',
            'name' => 'Ada',
            'password' => 'temporary',
            'requirePasswordChange' => true,
            'roles' => ['user'],
        ], json_decode((string) $this->lastRequest()->getBody(), true));
    }

    public function testMessagesArePostedToTheGivenChannel(): void
    {
        $this->handler->append($this->answer(['message' => ['_id' => 'm1', 'msg' => 'Hello']]));

        $message = $this->client()->postMessage('@ada', 'Hello');

        $this->assertSame('m1', $message['_id']);
        $this->assertSame('/api/v1/chat.postMessage', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['channel' => '@ada', 'text' => 'Hello'], json_decode((string) $this->lastRequest()->getBody(), true));
    }

    public function testItIsDeclaredAsASymfonyRemoteClient(): void
    {
        $definition = new ClientDefinition(
            key: 'rocket_chat',
            label: 'Rocket.Chat',
            class: RocketChatClient::class,
            baseUrl: 'https://chat.example.test',
            apiKey: 'token',
            apiKeyRequired: true,
            headers: [RocketChatClient::HEADER_USER_ID => 'bot'],
            options: new ClientOptions(timeout: 10.0),
        );
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $client = (new ApiClientFactory($stack))->create($definition);
        $this->handler->append($this->answer(['_id' => 'bot']));

        $status = (new RemoteRegistry([new ApiClientRemote($definition, $client)]))->check('rocket_chat');

        $this->assertInstanceOf(RocketChatClient::class, $client);
        $this->assertSame(RemoteState::Up, $status->state);
        $this->assertSame('token', $this->lastRequest()->getHeaderLine(RocketChatClient::HEADER_AUTH_TOKEN));
    }

    private function client(): RocketChatClient
    {
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));

        return new RocketChatClient(
            'https://chat.example.test',
            'token',
            new GuzzleClient(['base_uri' => 'https://chat.example.test/', 'handler' => $stack]),
            [RocketChatClient::HEADER_USER_ID => 'bot'],
        );
    }

    private function answer(array $data, bool $success = true): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data + ['success' => $success]));
    }

    /**
     * @return array<int, array{_id: string, username: string}>
     */
    private function users(int $from, int $count): array
    {
        return array_map(
            static fn (int $index): array => ['_id' => 'id'.$index, 'username' => 'user'.$index],
            range($from, $from + $count - 1)
        );
    }

    private function lastRequest(): RequestInterface
    {
        return end($this->history)['request'];
    }
}
