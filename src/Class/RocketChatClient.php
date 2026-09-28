<?php

namespace Wexample\SymfonyRemoteRocketChat\Class;

use Wexample\PhpApi\Common\AbstractApiClient;
use Wexample\PhpApi\Enum\HttpMethod;
use Wexample\PhpApi\Exceptions\ApiException;
use Wexample\SymfonyRemoteRocketChat\Exception\RocketChatException;

/**
 * Rocket.Chat REST API (v1), authenticated with a personal access token.
 *
 * It keeps php-api Client's constructor, so symfony-remote builds it from
 * configuration: `api_key` is the token, `headers.X-User-Id` the id of the
 * user owning it.
 *
 * Answers are returned as Rocket.Chat sends them (arrays): mapping them to an
 * application's model is the caller's job, e.g. a data-sync adapter.
 */
class RocketChatClient extends AbstractApiClient
{
    public const string USER_AGENT = 'wexample-symfony-remote-rocket-chat';

    /**
     * Requires authentication: the remote reads Up only when the token works,
     * not merely when the server answers.
     */
    public const string PING_PATH = 'api/v1/me';

    public const string HEADER_AUTH_TOKEN = 'X-Auth-Token';

    public const string HEADER_USER_ID = 'X-User-Id';

    /**
     * Items asked per page when listing; Rocket.Chat caps the count server-side.
     */
    public const int PAGE_SIZE = 100;

    /**
     * Rocket.Chat reads its token from its own header, not from a Bearer one.
     */
    public function setApiKey(string $apiKey): void
    {
        $this->setDefaultHeader(self::HEADER_AUTH_TOKEN, $apiKey);
    }

    /**
     * @return array<string, mixed> the user owning the token
     */
    public function me(): array
    {
        return $this->call(HttpMethod::GET, 'me');
    }

    /**
     * @return iterable<array<string, mixed>> every user, page after page
     */
    public function listUsers(): iterable
    {
        return $this->paginate('users.list', 'users');
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(string $userId): array
    {
        return $this->call(HttpMethod::GET, 'users.info', ['query' => ['userId' => $userId]])['user'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserByUsername(string $username): array
    {
        return $this->call(HttpMethod::GET, 'users.info', ['query' => ['username' => $username]])['user'];
    }

    /**
     * Pass a random temporary password: Rocket.Chat asks the user to change
     * it at first login unless told otherwise. Never pass a password hash.
     *
     * @param array<string, mixed> $extra other users.create fields (roles, active, …)
     *
     * @return array<string, mixed>
     */
    public function createUser(
        string $username,
        string $email,
        string $name,
        string $password,
        bool $requirePasswordChange = true,
        array $extra = [],
    ): array {
        return $this->call(HttpMethod::POST, 'users.create', ['json' => [
            'username' => $username,
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'requirePasswordChange' => $requirePasswordChange,
        ] + $extra])['user'];
    }

    /**
     * @param array<string, mixed> $data fields to change (username, email, name, …)
     *
     * @return array<string, mixed>
     */
    public function updateUser(string $userId, array $data): array
    {
        return $this->call(HttpMethod::POST, 'users.update', ['json' => [
            'userId' => $userId,
            'data' => $data,
        ]])['user'];
    }

    /**
     * Deactivating keeps the account and its history; prefer it to deleting.
     *
     * @return array<string, mixed>
     */
    public function setUserActiveStatus(string $userId, bool $active): array
    {
        return $this->call(HttpMethod::POST, 'users.setActiveStatus', ['json' => [
            'userId' => $userId,
            'activeStatus' => $active,
        ]])['user'];
    }

    public function deleteUser(string $userId): void
    {
        $this->call(HttpMethod::POST, 'users.delete', ['json' => ['userId' => $userId]]);
    }

    /**
     * Every private group of the server; needs an admin token.
     *
     * @return iterable<array<string, mixed>>
     */
    public function listGroups(): iterable
    {
        return $this->paginate('groups.listAll', 'groups');
    }

    /**
     * @param string[] $members usernames
     *
     * @return array<string, mixed>
     */
    public function createGroup(string $name, array $members = []): array
    {
        return $this->call(HttpMethod::POST, 'groups.create', ['json' => [
            'name' => $name,
            'members' => $members,
        ]])['group'];
    }

    /**
     * @return array<string, mixed>
     */
    public function renameGroup(string $roomId, string $name): array
    {
        return $this->call(HttpMethod::POST, 'groups.rename', ['json' => [
            'roomId' => $roomId,
            'name' => $name,
        ]])['group'];
    }

    /**
     * Archiving keeps the history; prefer it to deleting.
     */
    public function archiveGroup(string $roomId): void
    {
        $this->call(HttpMethod::POST, 'groups.archive', ['json' => ['roomId' => $roomId]]);
    }

    public function deleteGroup(string $roomId): void
    {
        $this->call(HttpMethod::POST, 'groups.delete', ['json' => ['roomId' => $roomId]]);
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function listChannels(): iterable
    {
        return $this->paginate('channels.list', 'channels');
    }

    /**
     * @param string $channel a room id, "#channel-name" or "@username" for a direct message
     *
     * @return array<string, mixed> the message as stored
     */
    public function postMessage(string $channel, string $text): array
    {
        return $this->call(HttpMethod::POST, 'chat.postMessage', ['json' => [
            'channel' => $channel,
            'text' => $text,
        ]])['message'];
    }

    /**
     * @return array<string, mixed>
     */
    private function call(HttpMethod $method, string $endpoint, array $options = []): array
    {
        try {
            $response = $this->requestJson($method, 'api/v1/'.$endpoint, $options);
        } catch (ApiException $exception) {
            throw RocketChatException::fromApiException($exception);
        }

        if (false === ($response['success'] ?? true)) {
            throw RocketChatException::fromResponse($response);
        }

        return $response;
    }

    /**
     * Follows offset and count until the total Rocket.Chat announces is read,
     * or a page comes back empty.
     *
     * @return iterable<array<string, mixed>>
     */
    private function paginate(string $endpoint, string $itemsKey): iterable
    {
        $offset = 0;

        do {
            $page = $this->call(HttpMethod::GET, $endpoint, ['query' => [
                'offset' => $offset,
                'count' => self::PAGE_SIZE,
            ]]);
            $items = $page[$itemsKey] ?? [];

            yield from $items;

            $offset += count($items);
        } while ([] !== $items && $offset < ($page['total'] ?? PHP_INT_MAX));
    }
}
