<?php

declare (strict_types=1);
namespace AcceptMarkdown\Vendor\League\HTMLToMarkdown\Converter;

use AcceptMarkdown\Vendor\League\HTMLToMarkdown\Configuration;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\ConfigurationAwareInterface;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\ElementInterface;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\RawHtml;
class DivConverter implements ConverterInterface, ConfigurationAwareInterface
{
    /** @var Configuration */
    protected $config;
    public function setConfig(Configuration $config): void
    {
        $this->config = $config;
    }
    public function convert(ElementInterface $element): string
    {
        if ($this->config->getOption('strip_tags', \false)) {
            return $element->getValue() . "\n\n";
        }
        return RawHtml::fromElement($element);
    }
    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['div'];
    }
}
