<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Controller\Adminhtml\Sso;

use MageDevGroup\AdminSso\Model\AdminSessionCreator;
use MageDevGroup\AdminSso\Model\Oidc\CallbackHandler;
use MageDevGroup\AdminSso\Model\RoleAssigner;
use MageDevGroup\AdminSso\Model\UserProvisioner;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Controller\Adminhtml\Auth;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Handles the admin OIDC redirect back from the IdP: surfaces IdP error responses,
 * hands the `code`/`state` to {@see CallbackHandler} for a verified identity, then
 * provisions the matching admin user (JIT), assigns its ACL role from the IdP
 * groups, and establishes the backend session.
 *
 * Extends the backend Auth controller so it is reachable before login (no ACL).
 * Any failure is turned into a login-page error message so the admin is never left
 * on a broken page; success lands on the admin startup page.
 */
class Callback extends Auth implements HttpGetActionInterface
{
    /**
     * @param Context $context
     * @param CallbackHandler $callbackHandler
     * @param UserProvisioner $userProvisioner
     * @param RoleAssigner $roleAssigner
     * @param AdminSessionCreator $adminSessionCreator
     * @param BackendUrlInterface $backendUrl
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly CallbackHandler $callbackHandler,
        private readonly UserProvisioner $userProvisioner,
        private readonly RoleAssigner $roleAssigner,
        private readonly AdminSessionCreator $adminSessionCreator,
        private readonly BackendUrlInterface $backendUrl,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Process the IdP callback and route the browser accordingly.
     *
     * On success the admin session is established and the browser lands on the
     * startup page; any failure returns to the login page with a message.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $request = $this->getRequest();

        $error = (string)$request->getParam('error');
        if ($error !== '') {
            $this->messageManager->addErrorMessage($this->describeIdpError($request));
            return $this->toLogin();
        }

        $code = (string)$request->getParam('code');
        $state = (string)$request->getParam('state');
        if ($code === '' || $state === '') {
            $this->messageManager->addErrorMessage(
                __('The SSO sign-in response was incomplete. Please try again.')
            );
            return $this->toLogin();
        }

        try {
            $identity = $this->callbackHandler->handle($code, $state);
            $user = $this->userProvisioner->provision($identity);
            $this->roleAssigner->assign($user, $identity);
            $this->adminSessionCreator->create($user);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->toLogin();
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not complete SSO sign-in. Please try again or contact your administrator.')
            );
            return $this->toLogin();
        }

        /** @var Redirect $result */
        $result = $this->resultRedirectFactory->create();
        $result->setPath($this->backendUrl->getStartupPageUrl());

        return $result;
    }

    /**
     * Redirect back to the admin login page.
     *
     * @return Redirect
     */
    private function toLogin(): Redirect
    {
        /** @var Redirect $result */
        $result = $this->resultRedirectFactory->create();
        $result->setPath('adminhtml/auth/login');

        return $result;
    }

    /**
     * Build a user-facing message from the IdP's `error`/`error_description` params.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     */
    private function describeIdpError($request): \Magento\Framework\Phrase
    {
        $description = trim((string)$request->getParam('error_description'));
        $detail = $description !== '' ? $description : (string)$request->getParam('error');

        // The message text is rendered as raw HTML on the login page (session messages
        // are not escaped by the renderer), so escape the attacker-controllable IdP
        // params here to prevent reflected XSS.
        return __('The identity provider rejected the sign-in: %1', $this->escaper->escapeHtml($detail));
    }
}
