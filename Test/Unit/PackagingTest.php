<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit;

use PHPUnit\Framework\TestCase;

class PackagingTest extends TestCase
{
    private const LICENSE_LINE =
        ' * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (preg_match('#/(vendor|\.git)/#', $path)) {
                continue;
            }
            if (in_array($file->getExtension(), ['php', 'xml', 'phtml'], true)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<string, true>
     */
    private function translations(): array
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $phrases = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $phrases[$row[0]] = true;
        }
        fclose($handle);

        return $phrases;
    }

    /**
     * Mirrors Magento's config converter: whitespace-only text nodes are skipped, the rest kept raw.
     */
    private function configText(\DOMElement $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_CDATA_SECTION_NODE || trim((string)$child->nodeValue, "\n ") !== '') {
                $text .= $child->nodeValue;
            }
        }

        return $text;
    }

    public function testEverySourceFileCarriesTheOslNotice(): void
    {
        foreach ($this->sourceFiles() as $path) {
            self::assertStringContainsString(self::LICENSE_LINE, (string)file_get_contents($path), $path);
        }
    }

    public function testComposerDeclaresVersionMagentoModulesAndSuggests(): void
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $composer['version'] ?? '');
        foreach (['backend', 'user', 'authorization', 'config'] as $module) {
            self::assertArrayHasKey('magento/module-' . $module, $composer['require']);
        }
        self::assertArrayHasKey('magento/module-two-factor-auth', $composer['suggest']);
        self::assertArrayHasKey('dmlab/module-admin-scim', $composer['suggest']);
    }

    public function testComposerSupportsMagento247AndPhp82(): void
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);

        self::assertSame('~8.2.0||~8.3.0||~8.4.0||~8.5.0', $composer['require']['php']);
        $magento247 = [
            'magento/framework' => '^103.0.7',
            'magento/module-authorization' => '^100.4.7',
            'magento/module-backend' => '^102.0.7',
            'magento/module-config' => '^101.2.7',
            'magento/module-user' => '^101.2.7',
        ];
        foreach ($magento247 as $package => $constraint) {
            self::assertSame($constraint, $composer['require'][$package] ?? null, $package);
        }
        foreach ($composer['require'] + $composer['require-dev'] as $package => $constraint) {
            self::assertStringStartsNotWith('>=', $constraint, $package);
            self::assertNotSame('*', $constraint, $package);
        }
    }

    public function testModuleSequenceCoversAlteredCoreModules(): void
    {
        $xml = simplexml_load_file($this->root() . '/etc/module.xml');
        $sequence = array_map('strval', $xml->xpath('/config/module/sequence/module/@name'));

        self::assertContains('Magento_Backend', $sequence);
        self::assertContains('Magento_User', $sequence);
    }

    public function testEveryLiteralPhraseIsTranslated(): void
    {
        $phrases = $this->translations();
        foreach ($this->sourceFiles() as $path) {
            if (str_contains($path, '/Test/')) {
                continue;
            }
            $literal = "'(?:[^'\\\\]|\\\\.)*'";
            preg_match_all(
                "#__\(\s*({$literal}(?:\s*\.\s*{$literal})*)\s*[,)]#",
                (string)file_get_contents($path),
                $matches
            );
            foreach ($matches[1] as $concatenation) {
                // Join 'a' . 'b' split literals into the phrase Magento's collector sees.
                preg_match_all("#'((?:[^'\\\\]|\\\\.)*)'#", $concatenation, $parts);
                self::assertArrayHasKey(stripslashes(implode('', $parts[1])), $phrases, $path);
            }
        }
    }

    public function testEverySystemConfigPhraseIsTranslated(): void
    {
        $phrases = $this->translations();
        $dom = new \DOMDocument();
        self::assertTrue($dom->load($this->root() . '/etc/adminhtml/system.xml'));
        foreach ((new \DOMXPath($dom))->query('//*[@translate]') as $node) {
            foreach (explode(' ', $node->getAttribute('translate')) as $child) {
                foreach ($node->getElementsByTagName($child) as $element) {
                    if ($element->parentNode === $node) {
                        self::assertArrayHasKey($this->configText($element), $phrases);
                    }
                }
            }
        }
    }
}
