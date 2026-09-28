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
