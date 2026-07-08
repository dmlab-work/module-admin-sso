<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\App\Action\Plugin;

use Magento\Backend\App\AbstractAction;
use Magento\Backend\App\Action\Plugin\Authentication as CoreAuthentication;
use Magento\Framework\App\RequestInterface;

/**
 * Opens the SSO start/callback actions to unauthenticated requests.
 *
 * The core plugin forwards every non-open backend action to the login page while
 * the user is not logged in — which the SSO flow always is when it begins. Without
 * this, {@see \MageDevGroup\AdminSso\Controller\Adminhtml\Sso\Start} and
 * {@see \MageDevGroup\AdminSso\Controller\Adminhtml\Sso\Callback} never dispatch,
 * so the browser is never sent to the IdP and the IdP redirect back is bounced.
 *
 * Unlike the core `_openActions` list — matched by bare action name across every
 * module — the exemption is scoped to this module's own route, so a same-named
 * action elsewhere is not made reachable before login.
 *
 * Mirrors {@see \Magento\AdminAdobeIms\App\Action\Plugin\Authentication}.
 */
class Authentication extends CoreAuthentication
{
    /** This module's admin route; the SSO actions are only opened on it. */
    private const SSO_ROUTE = 'adminsso';

    /** SSO controller actions reachable before login. */
    private const SSO_OPEN_ACTIONS = ['start', 'callback'];

    /**
     * @param AbstractAction $subject
     * @param \Closure $proceed
     * @param RequestInterface $request
     * @return mixed
     */
    public function aroundDispatch(
        AbstractAction $subject,
        \Closure $proceed,
        RequestInterface $request
    ) {
        if ($request->getRouteName() === self::SSO_ROUTE
            && in_array($request->getActionName(), self::SSO_OPEN_ACTIONS, true)
        ) {
            $request->setDispatched(true);
            $this->_auth->getAuthStorage()->refreshAcl();

            return $proceed($request);
        }

        return parent::aroundDispatch($subject, $proceed, $request);
    }
}
