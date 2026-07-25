<?php

namespace justinholtweb\headcount\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class HeadcountAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['css/headcount-cp.css'];
        $this->js = ['js/headcount-cp.js'];

        parent::init();
    }
}
