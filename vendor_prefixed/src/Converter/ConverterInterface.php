<?php

declare (strict_types=1);
namespace AcceptMarkdown\Vendor\League\HTMLToMarkdown\Converter;

use AcceptMarkdown\Vendor\League\HTMLToMarkdown\ElementInterface;
interface ConverterInterface
{
    public function convert(ElementInterface $element): string;
    /**
     * @return string[]
     */
    public function getSupportedTags(): array;
}
