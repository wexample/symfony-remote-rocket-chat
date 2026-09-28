<?php

namespace Wexample\SymfonyRemoteRocketChat\Exception;

use RuntimeException;
use Wexample\PhpApi\Exceptions\ApiException;

/**
 * A request Rocket.Chat refused or could not answer, carrying the reason it
 * gave ("error" in its answer) rather than the raw HTTP status.
 */
final class RocketChatException extends RuntimeException
{
    private ?ApiException $apiException = null;

    public static function fromApiException(ApiException $exception): self
    {
        $error = $exception->getResponseData()['error'] ?? null;

        $rocketChatException = new self(
            is_string($error) && '' !== $error ? $error : $exception->getMessage(),
            $exception->getCode(),
            $exception
        );
        $rocketChatException->apiException = $exception;

        return $rocketChatException;
    }

    /**
     * Rocket.Chat answered 200 with "success": false.
     */
    public static function fromResponse(array $response): self
    {
        $error = $response['error'] ?? null;

        return new self(is_string($error) && '' !== $error ? $error : 'Rocket.Chat refused the request.');
    }

    /**
     * Whether the same request may succeed later; a refusal Rocket.Chat
     * answered with a reason never does.
     */
    public function isTransient(): bool
    {
        return (bool) $this->apiException?->isTransient();
    }
}
