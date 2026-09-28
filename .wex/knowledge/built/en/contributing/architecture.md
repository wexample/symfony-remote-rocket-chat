## Architecture

src/Class/RocketChatClient.php extends php-api's `AbstractApiClient` and keeps its constructor, which is what lets `symfony-remote` build it from configuration and pick up its options, retries and shared quota.

- Authentication: `setApiKey()` is overridden to write `X-Auth-Token` instead of php-api's `Authorization: Bearer`; `X-User-Id` arrives through the default headers.
- `PING_PATH` is `api/v1/me`, which requires authentication, so the remote reads Up only when the token works.
- Every call goes through `call()`: it prefixes `api/v1/`, turns php-api's `ApiException` into a src/Exception/RocketChatException.php carrying Rocket.Chat's `error` field, and also rejects a 200 answer flagged `"success": false`. The exception keeps the `ApiException` to answer `isTransient()`.
- Listings go through `paginate()`, a generator following `offset` and `count` (`PAGE_SIZE`, 100) until the announced `total` is read or a page comes back empty. Reads are idempotent GETs, so php-api's retries apply to them; POSTs are never retried.

The bundle registers src/Service/RocketChatUserAdapter.php; the client itself is declared by the application under `wexample_symfony_remote.clients`. The adapter flattens a user into `RemoteItem` fields, reads a refused lookup by id as absence (Rocket.Chat answers an unknown id with an error, not an empty result) while letting an outage raise, searches by email through `users.list`'s `query` parameter with the address lowercased as Rocket.Chat stores it, and creates accounts with a random password to change.

Tests: tests/Unit/Class/RocketChatClientTest.php answers from a Guzzle `MockHandler`, and builds the client once through `symfony-remote`'s `ApiClientFactory` to check the configuration path end to end.
