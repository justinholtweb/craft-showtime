<?php

namespace justinholtweb\stub\assetbundles\calendar;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class CalendarAssetBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->depends = [CpAsset::class];

        // FullCalendar v6 injects its own styles from the JS bundle; there is no
        // companion stylesheet to load (the v5-era `index.global.min.css` 404s).
        $this->js = [
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
            'calendar-init.js',
        ];

        parent::init();
    }
}
