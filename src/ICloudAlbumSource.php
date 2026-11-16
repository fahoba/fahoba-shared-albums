<?php

declare(strict_types=1);

namespace Fahoba\SharedAlbums;

use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Http\Outbound;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Photos\RemotePhotoSource;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A shared iCloud album with "Public Website" switched on.
 *
 * Apple offers no interface; this reads what icloud.com/sharedalbum itself
 * asks for: the album's stream (`webstream`), then short-lived addresses of
 * the pictures (`webasseturls`). The album lives on one of Apple's
 * partitions; a wrong guess answers 330 with the right host, which is taken
 * only when it is one of Apple's.
 */
final class ICloudAlbumSource extends RemotePhotoSource implements DescribesSetup, UsesCredentials
{
    public const KEY = 'fahoba/icloud-album';

    private const TOKEN = '/^[A-Za-z0-9]{10,40}$/';

    public function __construct(private readonly Outbound $outbound) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('iCloud shared album');
    }

    public function isConfigured(?ConnectedAccount $account): bool
    {
        return parent::isConfigured($account) && self::token($account?->credentials()['url'] ?? null) !== null;
    }

    protected function listing(ConnectedAccount $account): array
    {
        $photos = [];

        foreach ($this->stream((string) self::token($account->credentials()['url'] ?? null))['photos'] ?? [] as $photo) {
            if (! is_array($photo) || ! is_string($photo['photoGuid'] ?? null) || ($photo['mediaAssetType'] ?? null) === 'video') {
                continue;
            }

            $created = $photo['dateCreated'] ?? null;
            $photos[] = new Photo(
                id: $photo['photoGuid'],
                caption: is_string($photo['caption'] ?? null) && trim($photo['caption']) !== '' ? trim($photo['caption']) : null,
                takenOn: is_string($created) && preg_match('/^\d{4}-\d{2}-\d{2}/', $created) === 1 ? substr($created, 0, 10) : null,
            );
        }

        return $photos;
    }

    protected function download(ConnectedAccount $account, string $id): ?string
    {
        $token = (string) self::token($account->credentials()['url'] ?? null);
        $stream = $this->stream($token);
        $photo = collect($stream['photos'] ?? [])->first(fn (mixed $entry): bool => is_array($entry) && ($entry['photoGuid'] ?? null) === $id);

        if (! is_array($photo)) {
            return null;
        }

        // The largest version up to the frame's size; Apple keys them by checksum.
        $versions = collect((array) ($photo['derivatives'] ?? []))
            ->filter(fn (mixed $version): bool => is_array($version) && is_string($version['checksum'] ?? null))
            ->sortBy(fn (array $version): int => (int) ($version['width'] ?? 0));
        $version = $versions->last(fn (array $version): bool => (int) ($version['width'] ?? 0) <= 2048) ?? $versions->first();

        if ($version === null) {
            return null;
        }

        $urls = $this->post($stream['host'], $token, 'webasseturls', ['photoGuids' => [$id]])->json('items') ?? [];
        $location = $urls[$version['checksum']] ?? null;

        if (! is_array($location) || ! is_string($location['url_location'] ?? null) || ! is_string($location['url_path'] ?? null)
            || ! str_ends_with($location['url_location'], '.icloud-content.com')) {
            return null;
        }

        $response = $this->outbound->server()->get('https://'.$location['url_location'].$location['url_path']);

        return $response->successful() ? $response->body() : null;
    }

    public function icon(): string
    {
        return 'heroicon-o-photo';
    }

    public function description(): string
    {
        return __('Shows a shared iCloud album by its public link — no Apple account is stored.');
    }

    public function setupNotes(): array
    {
        return [
            __('In the Photos app, open the shared album, tap the people icon and switch on “Public Website”; copy that link.'),
            __('Anyone with the link can see the album — that is how Apple shares it.'),
            __('Apple documents no interface for this. It works the way icloud.com reads the album and may stop without notice.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(name: 'url', label: __('Link to the album'), placeholder: 'https://www.icloud.com/sharedalbum/#B0aGWZuqDGsXXXX'),
            new CredentialField(name: 'name', label: __('Name'), required: false, placeholder: __('Grandma & Grandpa')),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        $token = self::token($credentials['url'] ?? null);

        if ($token === null) {
            return __('This is not a link to a shared iCloud album.');
        }

        try {
            $this->stream($token);
        } catch (RuntimeException) {
            return __('The album could not be read. Is “Public Website” switched on?');
        }

        return null;
    }

    public function accountLabel(array $credentials): string
    {
        $name = trim((string) ($credentials['name'] ?? ''));

        return $name !== '' ? $name : __('iCloud shared album');
    }

    /** @return array{host: string, photos: array<int, mixed>} */
    private function stream(string $token): array
    {
        $host = self::partition($token);
        $response = $this->post($host, $token, 'webstream', ['streamCtag' => null]);

        if ($response->status() === 330) {
            $moved = (string) ($response->json('X-Apple-MMe-Host') ?? $response->header('X-Apple-MMe-Host'));

            if (preg_match('/^p\d{2}-sharedstreams\.icloud\.com$/', $moved) !== 1) {
                throw new RuntimeException('iCloud named an unexpected host.');
            }

            $host = $moved;
            $response = $this->post($host, $token, 'webstream', ['streamCtag' => null]);
        }

        if (! $response->successful() || ! is_array($response->json('photos'))) {
            throw new RuntimeException('iCloud did not hand out the album.');
        }

        return ['host' => $host, 'photos' => $response->json('photos')];
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $host, string $token, string $call, array $body): Response
    {
        return $this->outbound->server()
            ->withHeaders(['Origin' => 'https://www.icloud.com'])
            ->withBody((string) json_encode($body), 'text/plain')
            ->post("https://{$host}/{$token}/sharedstreams/{$call}");
    }

    /** The token after `#` in the album's link. */
    private static function token(mixed $url): ?string
    {
        $token = (string) (parse_url(trim((string) $url), PHP_URL_FRAGMENT) ?? '');
        $token = explode(';', $token)[0];

        return preg_match(self::TOKEN, $token) === 1 ? $token : null;
    }

    /** Apple's partition is encoded in the token: one base-62 digit after an `A`, two otherwise. */
    private static function partition(string $token): string
    {
        $digits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $encoded = $token[0] === 'A' ? $token[1] : substr($token, 1, 2);
        $number = 0;

        foreach (str_split($encoded) as $char) {
            $number = $number * 62 + (int) strpos($digits, $char);
        }

        return sprintf('p%02d-sharedstreams.icloud.com', max(1, min(99, $number)));
    }
}
