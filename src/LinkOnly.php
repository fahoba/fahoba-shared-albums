<?php

declare(strict_types=1);

namespace Fahoba\SharedAlbums;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use LogicException;

/**
 * Typed-in credentials before there is a connection: the wizard checks a
 * link by listing it once, with nothing stored and nothing to refresh.
 *
 * @internal
 */
final readonly class LinkOnly implements ConnectedAccount
{
    /** @param  array<string, mixed>  $credentials */
    public function __construct(private array $credentials) {}

    public function accountId(): int
    {
        return 0;
    }

    public function householdId(): int
    {
        return 0;
    }

    public function areaKey(): string
    {
        return 'photos';
    }

    public function providerKey(): string
    {
        return SynologyAlbumSource::KEY;
    }

    public function credentials(): array
    {
        return $this->credentials;
    }

    public function settings(): array
    {
        return [];
    }

    public function accessToken(): ?string
    {
        return null;
    }

    public function refreshToken(): ?string
    {
        return null;
    }

    public function tokenExpiresAt(): ?CarbonImmutable
    {
        return null;
    }

    public function accessTokenHasExpired(): bool
    {
        return false;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function storeSettings(array $settings): void
    {
        throw new LogicException('Nothing is stored while checking a link.');
    }

    public function storeToken(?string $accessToken, ?CarbonImmutable $expiresAt, ?string $refreshToken = null): void
    {
        throw new LogicException('Nothing is stored while checking a link.');
    }

    public function markInvalid(): void {}
}
