<?php

declare(strict_types=1);

namespace Fahoba\SharedAlbums;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

/**
 * Albums that are shared by a link, read without an account. Neither
 * service documents what its web page asks for; both may change it without
 * notice, and the wizard says so.
 */
final class SharedAlbumsPlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);
        $catalog->offer('photos', ICloudAlbumSource::KEY, ICloudAlbumSource::class);
        $catalog->offer('photos', SynologyAlbumSource::KEY, SynologyAlbumSource::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
