<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit;

use PHPUnit\Framework\TestCase;

class DiConfigTest extends TestCase
{
    private function xpath(string $file = 'etc/di.xml'): \DOMXPath
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 2) . '/' . $file));

        return new \DOMXPath($dom);
    }

    public function testClientSecretIsMarkedSensitive(): void
    {
        $items = $this->xpath()->query(
            '/config/type[@name="Magento\Config\Model\Config\TypePool"]'
            . '/arguments/argument[@name="sensitive"]'
            . '/item[@name="dmlab_admin_sso/general/client_secret"]'
        );

        self::assertSame(1, $items->length);
        self::assertSame('1', trim($items->item(0)->textContent));
    }

    public function testEnforceSsoPluginsUserAuthenticateInGlobalScope(): void
    {
        // Global scope so webapi_rest (admin token endpoint) is covered, not just adminhtml.
        $query = '/config/type[@name="Magento\User\Model\User"]'
            . '/plugin[@name="dmlab_admin_sso_enforce"][@type="DmLab\AdminSso\Plugin\Backend\EnforceSso"]';

        self::assertSame(1, $this->xpath()->query($query)->length);
        self::assertSame(0, $this->xpath('etc/adminhtml/di.xml')->query($query)->length);
        self::assertTrue(method_exists(\Magento\User\Model\User::class, 'authenticate'));
    }

    public function testBreakGlassIsOffByDefault(): void
    {
        $nodes = $this->xpath('etc/config.xml')->query('/config/default/dmlab_admin_sso/general/break_glass');

        self::assertSame(1, $nodes->length);
        self::assertSame('0', trim($nodes->item(0)->textContent));
    }

    public function testTrustIdpMfaIsOnByDefault(): void
    {
        // On, but 2FA is only skipped with amr/acr MFA evidence (IdpMfaPolicy).
        $nodes = $this->xpath('etc/config.xml')->query('/config/default/dmlab_admin_sso/general/trust_idp_mfa');

        self::assertSame(1, $nodes->length);
        self::assertSame('1', trim($nodes->item(0)->textContent));
    }
}
