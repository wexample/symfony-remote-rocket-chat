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
