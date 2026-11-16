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
 * An album of Synology Photos shared by a public link.
 *
 * Read the way the sharing page itself reads it: the page hands out a
 * session cookie, then `SYNO.Foto.Browse.Item` lists and `SYNO.Foto.Thumbnail`
 * delivers the large preview. Synology documents none of it; a DSM update may
 * change it. A link with a password is not supported.
 *
 * The NAS address is the household's, so every request goes through the
 * core's checked outbound requests: a NAS only reachable at home is refused.
 */
final class SynologyAlbumSource extends RemotePhotoSource implements DescribesSetup, UsesCredentials
{
    public const KEY = 'fahoba/synology-album';

    private const PAGE = 500;

    private const MAX_FILES = 5000;

    public function __construct(private readonly Outbound $outbound) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Synology Photos';
    }

    public function isConfigured(?ConnectedAccount $account): bool
    {
        return parent::isConfigured($account) && self::link($account?->credentials()['url'] ?? null) !== null;
    }

    protected function listing(ConnectedAccount $account): array
    {
        $link = self::link($account->credentials()['url'] ?? null) ?? throw new RuntimeException('No sharing link.');
        $session = $this->session($link);
        $photos = [];

        for ($offset = 0; $offset < self::MAX_FILES; $offset += self::PAGE) {
            $items = $this->api($link, $session, [
                'api' => 'SYNO.Foto.Browse.Item',
                'method' => 'list',
                'version' => '1',
                'offset' => (string) $offset,
                'limit' => (string) self::PAGE,
                'additional' => '["thumbnail"]',
            ])['data']['list'] ?? null;

            if (! is_array($items)) {
                throw new RuntimeException('Synology did not list the album.');
            }

            foreach ($items as $item) {
                $thumbnail = $item['additional']['thumbnail'] ?? null;

                if (! is_array($item) || ($item['type'] ?? null) !== 'photo' || ! is_array($thumbnail)
                    || ! is_numeric($thumbnail['unit_id'] ?? null) || ! is_string($thumbnail['cache_key'] ?? null)) {
                    continue;
                }

                $photos[] = new Photo(
                    id: self::encodeId($thumbnail['unit_id'].'|'.$thumbnail['cache_key']),
                    takenOn: is_numeric($item['time'] ?? null) ? gmdate('Y-m-d', (int) $item['time']) : null,
                );
            }

            if (count($items) < self::PAGE) {
                break;
            }
        }

        return $photos;
    }

    protected function download(ConnectedAccount $account, string $id): ?string
    {
        $link = self::link($account->credentials()['url'] ?? null);
        [$unit, $cacheKey] = array_pad(explode('|', (string) self::decodeId($id), 2), 2, '');

        if ($link === null || ! ctype_digit($unit) || $cacheKey === '') {
            return null;
        }

        $response = $this->request($link, $this->session($link), [
            'api' => 'SYNO.Foto.Thumbnail',
            'method' => 'get',
            'version' => '2',
            'mode' => 'download',
            'id' => $unit,
            'type' => 'unit',
            'size' => 'xl',
            'cache_key' => $cacheKey,
        ]);

        return $response !== null && $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')
            ? $response->body()
            : null;
    }

    public function icon(): string
    {
        return 'heroicon-o-server';
    }

    public function description(): string
    {
        return __('Shows an album of Synology Photos by its sharing link.');
    }

    public function setupNotes(): array
    {
        return [
            __('In Synology Photos, share the album, switch on the public link without a password and copy it.'),
            __('The NAS must be reachable from the internet at this address — an address only valid at home is refused.'),
            __('Synology documents no interface for this. It works the way the sharing page reads the album and may stop after an update.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(name: 'url', label: __('Link to the album'), placeholder: 'https://nas.example.org/mo/sharing/AbCdEfGh'),
            new CredentialField(name: 'name', label: __('Name'), required: false, placeholder: __('Grandma & Grandpa')),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        $link = self::link($credentials['url'] ?? null);

        if ($link === null) {
            return __('This is not a sharing link of Synology Photos.');
        }

        try {
            $this->listing(new LinkOnly($credentials));
        } catch (RuntimeException) {
            return __('The album could not be read. Is the link public and without a password?');
        }

        return null;
    }

    public function accountLabel(array $credentials): string
    {
        $name = trim((string) ($credentials['name'] ?? ''));

        return $name !== '' ? $name : 'Synology Photos';
    }

    /**
     * The sharing page's session cookie.
     *
     * @param  array{base: string, passphrase: string}  $link
     */
    private function session(array $link): string
    {
        $response = $this->outbound->householdGet($link['base'].'/mo/sharing/'.$link['passphrase'], 15);

        foreach ($response?->headers()['Set-Cookie'] ?? [] as $cookie) {
            if (preg_match('/^sharing_sid=([^;]+)/', (string) $cookie, $match) === 1) {
                return $match[1];
            }
        }

        throw new RuntimeException('Synology handed out no sharing session.');
    }

    /**
     * @param  array{base: string, passphrase: string}  $link
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function api(array $link, string $session, array $query): array
    {
        $response = $this->request($link, $session, $query);
        $data = $response?->json();

        if (! is_array($data) || ($data['success'] ?? false) !== true) {
            throw new RuntimeException('Synology refused the request.');
        }

        return $data;
    }

    /**
     * @param  array{base: string, passphrase: string}  $link
     * @param  array<string, string>  $query
     */
    private function request(array $link, string $session, array $query): ?Response
    {
        return $this->outbound->householdSend('POST', $link['base'].'/mo/sharing/webapi/entry.cgi', [
            'headers' => [
                'X-SYNO-SHARING' => $link['passphrase'],
                'Cookie' => 'sharing_sid='.$session,
            ],
            'form_params' => [...$query, 'passphrase' => $link['passphrase']],
        ], 30);
    }

    /**
     * NAS address and passphrase from a link like `https://nas/mo/sharing/AbC`
     * or `https://nas/photo/mo/sharing/AbC`.
     *
     * @return array{base: string, passphrase: string}|null
     */
    private static function link(mixed $url): ?array
    {
        $url = trim((string) $url);

        if (preg_match('#^(https?://[^/\s]+(?:/photo)?)/mo/sharing/([A-Za-z0-9_-]{4,64})/?$#i', $url, $match) !== 1) {
            return null;
        }

        return ['base' => $match[1], 'passphrase' => $match[2]];
    }
}
