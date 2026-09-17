<?php

declare(strict_types=1);

namespace Jengo\Broadcasting\Security;

use Closure;
use Jengo\Broadcasting\Contracts\ChannelInterface;

class ChannelAuthorizer
{
    /**
     * Registered channel patterns and callbacks.
     *
     * @var array<string, array{pattern: string, regex: string, callback: callable, options: array<string, mixed>}>
     */
    protected array $channels = [];

    /**
     * Custom user resolver.
     *
     * @var (callable(): mixed)|null
     */
    protected $userResolver = null;

    /**
     * Register a channel authorization callback.
     *
     * @param string                $pattern Channel pattern with optional wildcards (e.g. "orders.{id}")
     * @param callable              $callback Callback receiving ($user, ...$wildcardValues)
     * @param array<string, mixed>  $options
     */
    public function channel(string $pattern, callable $callback, array $options = []): self
    {
        $normalizedPattern = $this->normalizeChannelName($pattern);
        $regex = $this->patternToRegex($normalizedPattern);

        $this->channels[$normalizedPattern] = [
            'pattern'  => $normalizedPattern,
            'regex'    => $regex,
            'callback' => $callback,
            'options'  => $options,
        ];

        return $this;
    }

    /**
     * Get all registered channels.
     *
     * @return array<string, array{pattern: string, regex: string, callback: callable, options: array<string, mixed>}>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Set the callback used to resolve the authenticated user.
     *
     * @param callable(): mixed $resolver
     */
    public function setUserResolver(callable $resolver): self
    {
        $this->userResolver = $resolver;
        return $this;
    }

    /**
     * Resolve the currently authenticated user.
     */
    public function resolveUser(): mixed
    {
        if ($this->userResolver !== null) {
            return ($this->userResolver)();
        }

        // Try Jengo / Shield auth() helper
        if (function_exists('auth')) {
            try {
                $auth = auth();
                if (is_object($auth) && method_exists($auth, 'user')) {
                    $user = $auth->user();
                    if ($user !== null) {
                        return $user;
                    }
                }
            } catch (\Throwable) {
                // Ignore and try fallback
            }
        }

        // Try session user
        if (function_exists('session')) {
            try {
                $sessionUser = session('user') ?? session('auth_user');
                if ($sessionUser !== null) {
                    return $sessionUser;
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        return null;
    }

    /**
     * Authorize a channel subscription for the given user.
     *
     * @param ChannelInterface|string $channel
     * @param mixed                   $user If null, automatically resolved
     * @return mixed Boolean for private channels, array/false for presence channels
     */
    public function authorize(ChannelInterface|string $channel, mixed $user = null): mixed
    {
        $user ??= $this->resolveUser();

        if ($user === null) {
            return false;
        }

        $channelName = $this->normalizeChannelName((string) $channel);

        foreach ($this->channels as $entry) {
            if (preg_match($entry['regex'], $channelName, $matches)) {
                $parameters = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        // Cast numeric parameters (including 0, but preserving padded numeric strings like '007')
                        $isLeadingZero = strlen($value) > 1 && str_starts_with($value, '0');
                        $parameters[$key] = (is_numeric($value) && ! $isLeadingZero && ! str_contains($value, '.'))
                            ? (int) $value
                            : $value;
                    }
                }

                $args = array_merge([$user], array_values($parameters));

                return ($entry['callback'])(...$args);
            }
        }

        return false;
    }

    /**
     * Check if a channel pattern has been registered.
     */
    public function hasChannel(string $pattern): bool
    {
        return isset($this->channels[$this->normalizeChannelName($pattern)]);
    }

    /**
     * Strip 'private-' or 'presence-' wire prefixes.
     */
    public function normalizeChannelName(string $channel): string
    {
        if (str_starts_with($channel, 'private-')) {
            return substr($channel, 8);
        }

        if (str_starts_with($channel, 'presence-')) {
            return substr($channel, 9);
        }

        return $channel;
    }

    /**
     * Convert pattern with {param} wildcards to regex.
     */
    protected function patternToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '#');
        $regex = preg_replace('/\\\\\\{([a-zA-Z0-9_]+)\\\\\\}/', '(?P<$1>[^.]+)', $escaped);

        return '#^' . $regex . '$#u';
    }
}
