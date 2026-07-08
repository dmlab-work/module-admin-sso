<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values, array $decrypt = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path) => (bool)($values[$path] ?? false));

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')
            ->willReturnCallback(static fn (string $value) => $decrypt[$value] ?? '');

        return new Config($scopeConfig, $encryptor);
    }

    public function testFlagsReadThroughIsSetFlag(): void
    {
        $config = $this->config([
            Config::XML_PATH_ENABLED => '1',
            Config::XML_PATH_ENFORCE_SSO => '1',
            Config::XML_PATH_BREAK_GLASS => '0',
        ]);

        self::assertTrue($config->isEnabled());
        self::assertTrue($config->isEnforceSso());
        self::assertFalse($config->isBreakGlassEnabled());
    }

    public function testFlagsDefaultToFalseWhenUnset(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isEnforceSso());
        self::assertFalse($config->isBreakGlassEnabled());
    }

    public function testGetClientIdTrimsAndTreatsBlankAsNull(): void
    {
        self::assertSame('cid-123', $this->config([Config::XML_PATH_CLIENT_ID => '  cid-123 '])->getClientId());
        self::assertNull($this->config([Config::XML_PATH_CLIENT_ID => '   '])->getClientId());
        self::assertNull($this->config([])->getClientId());
    }

    public function testGetClientSecretDecryptsStoredValue(): void
    {
        $config = $this->config(
            [Config::XML_PATH_CLIENT_SECRET => 'enc:blob'],
            ['enc:blob' => 'super-secret']
        );

        self::assertSame('super-secret', $config->getClientSecret());
    }

    public function testGetClientSecretReturnsNullWhenUnsetOrEmptyAfterDecrypt(): void
    {
        self::assertNull($this->config([])->getClientSecret());
        self::assertNull($this->config([Config::XML_PATH_CLIENT_SECRET => ''])->getClientSecret());
        // Stored but decrypts to empty (e.g. key rotation) → null, not a stray value.
        self::assertNull($this->config([Config::XML_PATH_CLIENT_SECRET => 'stale'])->getClientSecret());
    }

    public function testGetGroupRoleMapParsesLines(): void
    {
        $raw = "# admins get super-user\nAdmins=1\r\n editors = 2 \n\nbad-line-no-equals\n=3\nfoo=\n";
        $config = $this->config([Config::XML_PATH_GROUP_ROLE_MAP => $raw]);

        self::assertSame(['Admins' => '1', 'editors' => '2'], $config->getGroupRoleMap());
    }

    public function testGetGroupRoleMapLastDuplicateWins(): void
    {
        $config = $this->config([Config::XML_PATH_GROUP_ROLE_MAP => "team=1\nteam=5"]);

        self::assertSame(['team' => '5'], $config->getGroupRoleMap());
    }

    public function testGetGroupRoleMapEmptyWhenUnsetOrBlank(): void
    {
        self::assertSame([], $this->config([])->getGroupRoleMap());
        self::assertSame([], $this->config([Config::XML_PATH_GROUP_ROLE_MAP => "  \n \n"])->getGroupRoleMap());
    }

    public function testGetDefaultRoleIdTrimsAndTreatsBlankAsNull(): void
    {
        self::assertSame('3', $this->config([Config::XML_PATH_DEFAULT_ROLE => ' 3 '])->getDefaultRoleId());
        self::assertNull($this->config([Config::XML_PATH_DEFAULT_ROLE => '  '])->getDefaultRoleId());
        self::assertNull($this->config([])->getDefaultRoleId());
    }
}
