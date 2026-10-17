<?php

declare (strict_types=1);
namespace AcceptMarkdown\Vendor\League\HTMLToMarkdown\Converter;

use AcceptMarkdown\Vendor\League\HTMLToMarkdown\Configuration;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\ConfigurationAwareInterface;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\ElementInterface;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\LinkSyntax;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\RawHtml;
class LinkConverter implements ConverterInterface, ConfigurationAwareInterface
{
    /** @var Configuration */
    protected $config;
    public function setConfig(Configuration $config): void
    {
        $this->config = $config;
    }
    public function convert(ElementInterface $element): string
    {
        $href = LinkSyntax::escapeDestination($element->getAttribute('href'));
        $title = LinkSyntax::escapeText($element->getAttribute('title'));
        $text = \trim($element->getValue(), "\t\n\r\x00\v");
        if ($title !== '') {
            $markdown = '[' . $text . '](' . $href . ' "' . $title . '")';
        } elseif ($href === $text && $this->isValidAutolink($href)) {
            $markdown = '<' . $href . '>';
        } elseif ($href === 'mailto:' . $text && $this->isValidEmail($text) && \strpbrk($text[0], '!?') === \false) {
            $markdown = '<' . $text . '>';
        } else {
            $markdown = '[' . $text . '](' . $href . ')';
        }
        if (!$href) {
            if ($this->shouldStrip()) {
                $markdown = $text;
            } else {
                $markdown = RawHtml::fromElement($element);
            }
        }
        return $markdown;
    }
    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['a'];
    }
    private function isValidAutolink(string $href): bool
    {
        $useAutolinks = $this->config->getOption('use_autolinks');
        return $useAutolinks && \preg_match('/^[A-Za-z][A-Za-z0-9.+-]{1,31}:[^<>\x00-\x20]*/i', $href) === 1;
    }
    private function isValidEmail(string $email): bool
    {
        // Email validation is messy business, but this should cover most cases
        return \filter_var($email, \FILTER_VALIDATE_EMAIL) !== \false;
    }
    private function shouldStrip(): bool
    {
        return \boolval($this->config->getOption('strip_placeholder_links') ?? \false);
    }
}
