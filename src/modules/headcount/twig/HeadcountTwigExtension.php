<?php

namespace justinholtweb\headcount\twig;

use justinholtweb\headcount\twig\tokenparsers\HeadcountGateTokenParser;
use Twig\Extension\AbstractExtension;

class HeadcountTwigExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'headcount';
    }

    public function getTokenParsers(): array
    {
        return [
            new HeadcountGateTokenParser(),
        ];
    }
}
