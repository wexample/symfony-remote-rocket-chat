# symfony-remote-rocket-chat

Version: 2.0.8

Rocket.Chat REST client for Symfony, built on wexample/php-api and declared as a symfony-remote client

## Table of Contents

- [Installation](#installation)
- [Introduction](#introduction)

## Installation

```php
// config/bundles.php
Wexample\SymfonyRemote\WexampleSymfonyRemoteBundle::class => ['all' => true],
Wexample\SymfonyRemoteRocketChat\WexampleSymfonyRemoteRocketChatBundle::class => ['all' => true],
```

The client is declared as a `symfony-remote` client. Authentication uses a personal access token, created in Rocket.Chat under *My Account › Personal Access Tokens*; the token is the `api_key`, and the id of the user owning it goes in the `X-User-Id` header:

```yaml
# config/packages/wexample_symfony_remote.yaml
wexample_symfony_remote:
  clients:
    rocket_chat:
      class: Wexample\SymfonyRemoteRocketChat\Class\RocketChatClient
      label: Rocket.Chat
      base_url: '%env(ROCKET_CHAT_URL)%'
      api_key: '%env(default::ROCKET_CHAT_TOKEN)%'
      headers: { X-User-Id: '%env(default::ROCKET_CHAT_USER_ID)%' }
      options: { timeout: 15, retries: 2 }
```

`RocketChatClient` then autowires, and `remote:status` checks the token against `api/v1/me`. Listing every private group needs a token of an admin user.

`symfony-remote-rocket-chat` is a Rocket.Chat REST client (API v1) for Symfony, built on `wexample/php-api` and declared through `symfony-remote`: users (paginated listing, read, create, update, deactivate, delete), private groups (listing, create, rename, archive, delete), channels (listing) and messages (`postMessage` to a room, `#channel` or `@username`). Refusals raise a `RocketChatException` carrying the reason Rocket.Chat gave; `isTransient()` tells an outage from a refusal.

Answers are returned as Rocket.Chat sends them. `RocketChatUserAdapter` maps users to `symfony-data-sync` remote items, so an application keeps its users and Rocket.Chat accounts in step with a definition, not code.
