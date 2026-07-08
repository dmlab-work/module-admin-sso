<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model;

use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Resolves the admin-selected active provider into its registered preset.
 *
 * The active provider code is stored in config (set in the admin UI); this maps
 * it to the concrete {@see ProviderPresetInterface} a plugin registered into the
 * {@see PresetRegistry}. Returns null when nothing is selected or the selected
 * code has no registered preset (e.g. its plugin was removed).
 */
class ActiveProviderResolver
{
    /** Config path holding the admin-selected active provider code. */
    public const XML_PATH_ACTIVE_PROVIDER = 'magedevgroup_admin_sso/general/active_provider';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param PresetRegistry $presetRegistry
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PresetRegistry $presetRegistry
    ) {
    }

    /**
     * Configured active provider code, or null when unset.
     */
    public function getActiveCode(): ?string
    {
        $code = $this->scopeConfig->getValue(self::XML_PATH_ACTIVE_PROVIDER);
        $code = is_string($code) ? trim($code) : '';

        return $code === '' ? null : $code;
    }

    /**
     * Active provider preset, or null when none is selected or registered.
     */
    public function getActive(): ?ProviderPresetInterface
    {
        $code = $this->getActiveCode();
        if ($code === null || !$this->presetRegistry->has($code)) {
            return null;
        }

        return $this->presetRegistry->get($code);
    }
}
