<?php

namespace Wexample\SymfonyRemoteRocketChat\Service;

use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Interface\DisablableRemoteAdapterInterface;
use Wexample\SymfonyDataSync\Interface\SearchableRemoteAdapterInterface;
use Wexample\SymfonyRemoteRocketChat\Class\RocketChatClient;
use Wexample\SymfonyRemoteRocketChat\Exception\RocketChatException;

/**
 * Rocket.Chat users as symfony-data-sync remote items, with the fields
 * username, email, name, roles and active. Which of them travel, match or
 * exclude is the definition's business.
 */
class RocketChatUserAdapter implements DisablableRemoteAdapterInterface, SearchableRemoteAdapterInterface
{
    public const string FIELD_ACTIVE = 'active';

    public const string FIELD_EMAIL = 'email';

    public const string FIELD_NAME = 'name';

    public const string FIELD_ROLES = 'roles';

    public const string FIELD_USERNAME = 'username';

    public function __construct(
        private readonly RocketChatClient $client,
    ) {
    }

    public function list(): iterable
    {
        foreach ($this->client->listUsers() as $user) {
            yield $this->toItem($user);
        }
    }

    /**
     * Rocket.Chat refuses a lookup of an unknown id rather than answering
     * "nothing": a refusal is read as absence, an outage still raises.
     */
    public function get(string $id): ?RemoteItem
    {
        try {
            return $this->toItem($this->client->getUser($id));
        } catch (RocketChatException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            return null;
        }
    }

    public function findBy(string $field, mixed $value): iterable
    {
        if (! is_string($value) || '' === $value) {
            return;
        }

        if (self::FIELD_EMAIL === $field) {
            foreach ($this->client->findUsersByEmail($value) as $user) {
                yield $this->toItem($user);
            }

            return;
        }

        if (self::FIELD_USERNAME === $field) {
            try {
                yield $this->toItem($this->client->getUserByUsername($value));
            } catch (RocketChatException $exception) {
                if ($exception->isTransient()) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * The account gets a random password its owner must change at first login.
     */
    public function create(array $fields): RemoteItem
    {
        return $this->toItem($this->client->createUser(
            (string) $fields[self::FIELD_USERNAME],
            (string) $fields[self::FIELD_EMAIL],
            (string) ($fields[self::FIELD_NAME] ?? $fields[self::FIELD_USERNAME]),
            bin2hex(random_bytes(16)),
            extra: array_intersect_key($fields, array_flip([self::FIELD_ROLES, self::FIELD_ACTIVE])),
        ));
    }

    public function update(string $id, array $fields): RemoteItem
    {
        return $this->toItem($this->client->updateUser(
            $id,
            array_intersect_key($fields, array_flip([self::FIELD_USERNAME, self::FIELD_EMAIL, self::FIELD_NAME, self::FIELD_ROLES, self::FIELD_ACTIVE]))
        ));
    }

    public function remove(string $id): void
    {
        $this->client->deleteUser($id);
    }

    public function isDisabled(RemoteItem $item): bool
    {
        return false === $item->get(self::FIELD_ACTIVE);
    }

    public function disable(string $id): void
    {
        $this->client->setUserActiveStatus($id, false);
    }

    /**
     * @param array<string, mixed> $user as Rocket.Chat answers it
     */
    private function toItem(array $user): RemoteItem
    {
        return new RemoteItem((string) $user['_id'], [
            self::FIELD_USERNAME => $user['username'] ?? null,
            self::FIELD_EMAIL => $user['emails'][0]['address'] ?? null,
            self::FIELD_NAME => $user['name'] ?? null,
            self::FIELD_ROLES => $user['roles'] ?? [],
            self::FIELD_ACTIVE => $user['active'] ?? true,
        ], $user);
    }
}
