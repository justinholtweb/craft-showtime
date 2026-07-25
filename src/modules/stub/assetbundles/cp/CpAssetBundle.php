<?php

namespace justinholtweb\stub\assetbundles\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class CpAssetBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->depends = [CpAsset::class];

        $this->css = [
            'cp.css',
        ];

        $this->js = [
            'cp.js',
        ];

        parent::init();
    }
}
