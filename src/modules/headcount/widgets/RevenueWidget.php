<?php

namespace justinholtweb\headcount\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\headcount\Headcount;

class RevenueWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('headcount', 'Revenue');
    }

    public static function icon(): ?string
    {
        return 'chart-bar';
    }

    public function getBodyHtml(): ?string
    {
        $mrr = Headcount::getInstance()->reporting->getMRR();
        $revenueOverTime = Headcount::getInstance()->reporting->getRevenueOverTime(30);

        return Craft::$app->getView()->renderTemplate('headcount/widgets/revenue', [
            'mrr' => $mrr,
            'revenueOverTime' => $revenueOverTime,
        ]);
    }

    public static function maxColspan(): ?int
    {
        return 3;
    }
}
