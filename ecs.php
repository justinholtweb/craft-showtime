<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/ecs.php',
    ]);

    // src/modules/* is a one-way vendored copy of each sub-plugin's own repo (see
    // bin/sync-modules.sh). It is linted there, and anything fixed here would be
    // overwritten on the next sync.
    $ecsConfig->skip([
        __DIR__ . '/src/modules/*',
    ]);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
