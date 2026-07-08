<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Controller\Adminhtml\Sso;

use MageDevGroup\AdminSso\Model\Oidc\AuthorizationStarter;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Controller\Adminhtml\Auth;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Starts the admin OIDC login: redirects the browser to the IdP authorization URL
 * built by {@see AuthorizationStarter}.
 *
 * Extends the backend Auth controller so it is reachable before login (no ACL).
 * When SSO is disabled or misconfigured the starter throws and the user is sent
 * back to the native admin login with an error message, so the login page is
 * never left in a broken state.
 */
class Start extends Auth implements HttpGetActionInterface
{
    /**
     * @param Context $context
     * @param AuthorizationStarter $authorizationStarter
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly AuthorizationStarter $authorizationStarter,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Redirect to the IdP, or back to the admin login on failure.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $result */
        $result = $this->resultRedirectFactory->create();

        try {
            return $result->setUrl($this->authorizationStarter->start());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not start SSO sign-in. Please try again or contact your administrator.')
            );
        }

        return $result->setPath('adminhtml/auth/login');
    }
}
