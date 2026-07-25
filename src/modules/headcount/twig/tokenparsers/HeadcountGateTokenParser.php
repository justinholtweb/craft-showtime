<?php

namespace justinholtweb\headcount\twig\tokenparsers;

use justinholtweb\headcount\twig\nodes\HeadcountGateNode;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

class HeadcountGateTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): HeadcountGateNode
    {
        $parser = $this->parser;
        $stream = $parser->getStream();
        $lineno = $token->getLine();

        // Parse attributes (planHandle='pro-monthly')
        $attributes = [];
        while (!$stream->test(Token::BLOCK_END_TYPE)) {
            $key = $stream->expect(Token::NAME_TYPE)->getValue();
            $stream->expect(Token::OPERATOR_TYPE, '=');
            $value = $parser->getExpressionParser()->parseExpression();
            $attributes[$key] = $value;
        }

        $stream->expect(Token::BLOCK_END_TYPE);

        // Parse body
        $body = $parser->subparse([$this, 'decideEnd'], true);
        $stream->expect(Token::BLOCK_END_TYPE);

        return new HeadcountGateNode($body, $attributes, $lineno, $this->getTag());
    }

    public function decideEnd(Token $token): bool
    {
        return $token->test('endheadcountGate');
    }

    public function getTag(): string
    {
        return 'headcountGate';
    }
}
