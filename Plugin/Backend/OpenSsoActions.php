<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Plugin\Backend;

use Magento\Backend\App\AbstractAction;
use Magento\Backend\App\Action\Plugin\Authentication;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;

/**
 * Opens the SSO start/callback actions to unauthenticated requests.
 *
 * The core {@see Authentication} plugin forwards every non-open backend action to the
 * login page while the user is not logged in — which the SSO flow always is when it
 * begins. This wraps that plugin (instead of replacing it with a `preference`, which
 * Magento_AdminAdobeIms already claims) and lets only this module's route through,
 * so a same-named action elsewhere is not made reachable before login.
 */
class OpenSsoActions
{
    /** This module's admin route; the SSO actions are only opened on it. */
    private const SSO_ROUTE = 'adminsso';

    /** SSO controller actions reachable before login. */
    private const SSO_OPEN_ACTIONS = ['start', 'callback'];

    /**
     * @param Auth $auth
     */
    public function __construct(
        private readonly Auth $auth
    ) {
    }

    /**
     * Dispatch the SSO actions as open actions; everything else goes through the core plugin.
     *
     * @param Authentication $subject
     * @param callable $proceed the core plugin's aroundDispatch
     * @param AbstractAction $action
     * @param \Closure $dispatch the action's dispatch chain
     * @param RequestInterface $request
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundAroundDispatch(
        Authentication $subject,
        callable $proceed,
        AbstractAction $action,
        \Closure $dispatch,
        RequestInterface $request
    ) {
        if ($request->getRouteName() !== self::SSO_ROUTE
            || !in_array($request->getActionName(), self::SSO_OPEN_ACTIONS, true)
        ) {
            return $proceed($action, $dispatch, $request);
        }

        // Same as the core plugin does for its own open actions.
        $request->setDispatched(true);
        $this->auth->getAuthStorage()->refreshAcl();

        return $dispatch($request);
    }
}
