<?php

namespace justinholtweb\stub\assetbundles\booking;

use craft\web\AssetBundle;

class BookingFormAssetBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->css = [
            'booking-form.css',
        ];

        $this->js = [
            'booking-form.js',
        ];

        parent::init();
    }
}
