# symfony-remote-rocket-chat

Version: 2.0.0

```php
public function __construct(private RocketChatClient $rocketChat) {}

foreach ($this->rocketChat->listUsers() as $user) {   // pages of 100, read lazily
    // $user['_id'], $user['username'], $user['emails'][0]['address'], $user['active'], …
}

$user = $this->rocketChat->createUser('ada', 'ada@example.org', 'Ada Lovelace', $temporaryPassword);
$this->rocketChat->setUserActiveStatus($user['_id'], false);   // rather than deleteUser()
$this->rocketChat->postMessage('@ada', 'Welcome!');
```

Create users with a random temporary password: `requirePasswordChange` is on by default, so Rocket.Chat asks for a new one at first login. Never send a local password hash.

Prefer `setUserActiveStatus(false)` and `archiveGroup()` to their delete counterparts: they keep the history.

## Syncing users

`RocketChatUserAdapter` exposes accounts with the fields `username`, `email`, `name`, `roles` and `active`. It can disable an account (kept, inactive) and search one by email or username, which lets `data-sync:run --local-id` handle a single user without listing the server. A definition reproducing network's rules — link, then email, then username; bots and protected accounts untouched; the username pushed:

```yaml
wexample_symfony_data_sync:
  definitions:
    chat_users:
      local: App\Entity\User
      adapter: Wexample\SymfonyRemoteRocketChat\Service\RocketChatUserAdapter
      match:
        - { local: email, remote: email, normalize: [email] }
        - { local: username, remote: username, normalize: [lower] }
      fields:
        username: username
        email: email
      local_exclude:
        - { field: enabled, operator: equals, value: false }
      remote_exclude:
        - { field: roles, operator: contains, value: bot }
        - { field: username, operator: in, value: [admin] }
        - { field: email, operator: empty }
      orphans: { local: create_remote }        # new users get an account
      excluded_local: disable_remote           # disabled users are deactivated, not deleted
```

Created accounts get a random password their owner must change at first login.

## Errors

```php
try {
    $this->rocketChat->renameGroup($roomId, 'project-apollo');
} catch (RocketChatException $exception) {
    $exception->getMessage();    // the reason Rocket.Chat gave
    $exception->isTransient();   // true for an outage, worth retrying later
}
```

## Table of Contents

- [Syncing users](#syncing-users)
- [Errors](#errors)
- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

src/Class/RocketChatClient.php extends php-api's `AbstractApiClient` and keeps its constructor, which is what lets `symfony-remote` build it from configuration and pick up its options, retries and shared quota.

- Authentication: `setApiKey()` is overridden to write `X-Auth-Token` instead of php-api's `Authorization: Bearer`; `X-User-Id` arrives through the default headers.
- `PING_PATH` is `api/v1/me`, which requires authentication, so the remote reads Up only when the token works.
- Every call goes through `call()`: it prefixes `api/v1/`, turns php-api's `ApiException` into a src/Exception/RocketChatException.php carrying Rocket.Chat's `error` field, and also rejects a 200 answer flagged `"success": false`. The exception keeps the `ApiException` to answer `isTransient()`.
- Listings go through `paginate()`, a generator following `offset` and `count` (`PAGE_SIZE`, 100) until the announced `total` is read or a page comes back empty. Reads are idempotent GETs, so php-api's retries apply to them; POSTs are never retried.

The bundle registers src/Service/RocketChatUserAdapter.php; the client itself is declared by the application under `wexample_symfony_remote.clients`. The adapter flattens a user into `RemoteItem` fields, reads a refused lookup by id as absence (Rocket.Chat answers an unknown id with an error, not an empty result) while letting an outage raise, searches by email through `users.list`'s `query` parameter with the address lowercased as Rocket.Chat stores it, and creates accounts with a random password to change.

Tests: tests/Unit/Class/RocketChatClientTest.php answers from a Guzzle `MockHandler`, and builds the client once through `symfony-remote`'s `ApiClientFactory` to check the configuration path end to end.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- php: >=8.5
- wexample/php-api: >=5.0.0
- wexample/symfony-helpers: >=12.0.0
- wexample/symfony-remote: >=2.0.0
- wexample/symfony-data-sync: >=3.0.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
