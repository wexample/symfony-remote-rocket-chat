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

```php
try {
    $this->rocketChat->renameGroup($roomId, 'project-apollo');
} catch (RocketChatException $exception) {
    $exception->getMessage();    // the reason Rocket.Chat gave
    $exception->isTransient();   // true for an outage, worth retrying later
}
```
