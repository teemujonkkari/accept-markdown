<?php

declare (strict_types=1);
namespace AcceptMarkdown\Vendor\League\HTMLToMarkdown\Converter;

use AcceptMarkdown\Vendor\League\HTMLToMarkdown\ElementInterface;
class HorizontalRuleConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        return "---\n\n";
    }
    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['hr'];
    }
}
