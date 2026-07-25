<?php

namespace justinholtweb\headcount\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\headcount\Headcount;

class MembershipOverviewWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('headcount', 'Membership Overview');
    }

    public static function icon(): ?string
    {
        return 'users';
    }

    public function getBodyHtml(): ?string
    {
        $stats = Headcount::getInstance()->reporting->getDashboardStats();

        return Craft::$app->getView()->renderTemplate('headcount/widgets/overview', [
            'stats' => $stats,
        ]);
    }

    public static function maxColspan(): ?int
    {
        return 2;
    }
}
