<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit;

use MageDevGroup\AdminSso\Block\Adminhtml\Login\Sso;
use MageDevGroup\AdminSso\Model\ActiveProviderResolver;
use MageDevGroup\AdminSso\Model\AdminSessionCreator;
use MageDevGroup\AdminSso\Model\Config;
use MageDevGroup\AdminSso\Model\Oidc\AuthorizationStarter;
use MageDevGroup\AdminSso\Model\Oidc\CallbackHandler;
use MageDevGroup\AdminSso\Model\PresetRegistry;
use MageDevGroup\AdminSso\Model\RoleAssigner;
use MageDevGroup\AdminSso\Model\TwoFactorAuth\SessionGranter;
use MageDevGroup\AdminSso\Model\UserProvisioner;
use MageDevGroup\SsoCore\Api\AuthorizationStateStorageInterface;
use MageDevGroup\SsoCore\Api\Data\AuthorizationStateInterface;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use MageDevGroup\SsoCore\Model\Data\Identity;
use MageDevGroup\SsoCore\Model\Mapping\MappingEngine;
use MageDevGroup\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use MageDevGroup\SsoCore\Model\Oidc\DiscoveryClient;
use MageDevGroup\SsoCore\Model\Oidc\IdentityFactory;
use MageDevGroup\SsoCore\Model\Oidc\IdTokenValidator;
use MageDevGroup\SsoCore\Model\Oidc\JwksClient;
use MageDevGroup\SsoCore\Model\Oidc\ProviderMetadata;
use MageDevGroup\SsoCore\Model\Oidc\TokenClient;
use MageDevGroup\SsoCore\Model\Oidc\TokenResponse;
use Jose\Component\Core\JWKSet;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Math\Random;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\Template\Context;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\Collection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end acceptance test for the whole admin-SSO orchestration.
 *
 * Wires the real module objects — config, preset registry/resolver, login block,
 * start + callback handlers, JIT provisioner, role assigner, session creator — with
 * a concrete stub preset, driving the exact sequence the controllers run:
 * config → button → start (persist state) → callback (consume same state → identity)
 * → JIT provision → role assignment → session establishment.
 *
 * Only the true external boundaries are stubbed: the sso-core network clients
 * (discovery/token/JWKS/validator) and Magento persistence/session. Everything the
 * module owns is exercised for real, including the one-time state round-trip through
 * {@see AuthorizationStateStorage} shared between start and callback.
 */
class AcceptanceFlowTest extends TestCase
{
    private const CALLBACK_URL = 'https://magento.loc/admin/adminsso/sso/callback';
    private const CLIENT_ID = 'admin-sso-client';
    private const CLIENT_SECRET = 'topsecret';
    private const SUBJECT = 'idp-subject-1';
    private const EMAIL = 'jane.doe@example.com';

    /** @var array<string,mixed> config store backing the real Config/resolver */
    private array $configValues = [
        'magedevgroup_admin_sso/general/enabled' => '1',
        'magedevgroup_admin_sso/general/active_provider' => 'stub',
        'magedevgroup_admin_sso/general/client_id' => self::CLIENT_ID,
        'magedevgroup_admin_sso/general/client_secret' => 'enc:' . self::CLIENT_SECRET,
        'magedevgroup_admin_sso/general/group_role_map' => 'admins=5',
        'magedevgroup_admin_sso/general/default_role' => '1',
    ];

    public function testFullSsoLoginFlowFromConfigToSession(): void
    {
        $preset = $this->stubPreset();
        $config = $this->config();
        $registry = new PresetRegistry([$preset]);
        $resolver = new ActiveProviderResolver($this->scopeConfig(), $registry);

        // 1. Config + registry expose the active provider.
        self::assertSame($preset, $resolver->getActive());

        // 2. Login button is visible and branded from the active preset.
        $block = $this->loginBlock($config, $resolver);
        self::assertTrue($block->isAvailable());
        self::assertSame('Sign in with Stub', $block->getButtonLabel());

        // 3. Start: build the IdP URL and persist the one-time state.
        $stateStorage = $this->inMemoryStateStorage();
        $metadata = $this->metadata();
        $authUrl = $this->starter($config, $resolver, $stateStorage, $metadata)->start();

        $state = $this->stateFromUrl($authUrl);
        self::assertNotSame('', $state, 'start() must emit a state param in the auth URL');

        // 4. Callback: consume the SAME state (round-trip) and produce the identity.
        $identity = $this->callbackHandler($config, $resolver, $stateStorage, $metadata)
            ->handle('auth-code', $state);
        self::assertSame(self::SUBJECT, $identity->getSubjectId());
        self::assertSame(self::EMAIL, $identity->getEmail());
        self::assertSame(['admins'], $identity->getGroups());

        // 5. JIT provision: no local admin matches → a new one is created.
        $userResource = $this->createMock(UserResource::class);
        $setDataCalls = [];
        $user = $this->newUser($setDataCalls);
        $provisioner = $this->provisioner($userResource, $user);
        $provisioned = $provisioner->provision($identity);
        self::assertSame($user, $provisioned);

        // 6. Role assignment: the "admins" group maps to ACL role 5 (which exists).
        $roleAssigner = new RoleAssigner($config, new MappingEngine(), $userResource, $this->roleFactory('5'));
        $roleAssigner->assign($provisioned, $identity);

        // 7. Session establishment: user logged in, 2FA satisfied, login recorded.
        // setUser is a magic setter routed through SessionManager::__call.
        $authSession = $this->createMock(AuthSession::class);
        $authSession->expects(self::once())->method('__call')->with('setUser', [$provisioned]);
        $authSession->expects(self::once())->method('processLogin');
        $granter = $this->createMock(SessionGranter::class);
        $granter->expects(self::once())->method('grant');
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects(self::once())
            ->method('dispatch')
            ->with('backend_auth_user_login_success', ['user' => $provisioned]);
        $userResource->expects(self::once())->method('recordLogin')->with($provisioned);

        (new AdminSessionCreator($authSession, $eventManager, $userResource, $granter))
            ->create($provisioned);

        // The new user carries the create payload, the IdP subject link, and role 5.
        $createPayload = $this->createPayload($setDataCalls);
        self::assertSame(self::EMAIL, $createPayload['email']);
        self::assertSame(self::SUBJECT, $createPayload[UserProvisioner::SUBJECT_FIELD]);
        self::assertContains(['role_id', 5], $setDataCalls, 'admins group must assign ACL role 5');
    }

    /**
     * The array payload passed to setData when creating the user.
     *
     * @param array<int,array{0:mixed,1:mixed}> $setDataCalls captured [$key,$value] pairs
     * @return array<string,mixed>
     */
    private function createPayload(array $setDataCalls): array
    {
        foreach ($setDataCalls as [$key]) {
            if (is_array($key) && array_key_exists('username', $key)) {
                return $key;
            }
        }

        self::fail('No user-create payload was captured.');
    }

    /**
     * Concrete stub provider preset — a plugin would register one like it.
     */
    private function stubPreset(): ProviderPresetInterface
    {
        return new class implements ProviderPresetInterface {
            public function getCode(): string
            {
                return 'stub';
            }

            public function getLabel(): string
            {
                return 'Stub IdP';
            }

            public function buildDiscoveryUrl(array $config): string
            {
                return 'https://idp.example/.well-known/openid-configuration';
            }

            public function getDefaultScopes(): array
            {
                return ['openid', 'email', 'groups'];
            }

            public function getGroupsClaim(): ?string
            {
                return 'groups';
            }

            public function getButtonLabel(): string
            {
                return 'Sign in with Stub';
            }

            public function getButtonIconUrl(): ?string
            {
                return null;
            }
        };
    }

    /**
     * Real Config over the array-backed scope config + a pass-through encryptor.
     */
    private function config(): Config
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn(string $value): string => str_starts_with($value, 'enc:') ? substr($value, 4) : $value
        );

        return new Config($this->scopeConfig(), $encryptor);
    }

    /**
     * Array-backed ScopeConfigInterface shared by Config and the resolver.
     *
     * @return ScopeConfigInterface&\PHPUnit\Framework\MockObject\Stub
     */
    private function scopeConfig(): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(fn(string $path) => $this->configValues[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn(string $path): bool => (bool)($this->configValues[$path] ?? false));

        return $scopeConfig;
    }

    /**
     * In-memory one-time state storage shared by start and callback, so the state
     * start persists is the one callback consumes (replay-protected: consumed once).
     * The session-backed production storage has its own unit test.
     */
    private function inMemoryStateStorage(): AuthorizationStateStorageInterface
    {
        return new class implements AuthorizationStateStorageInterface {
            /** @var array<string,AuthorizationStateInterface> */
            private array $states = [];

            public function save(AuthorizationStateInterface $state): void
            {
                $this->states[$state->getState()] = $state;
            }

            public function consume(string $state): ?AuthorizationStateInterface
            {
                $entry = $this->states[$state] ?? null;
                unset($this->states[$state]);

                return $entry;
            }
        };
    }

    private function loginBlock(Config $config, ActiveProviderResolver $resolver): Sso
    {
        return (new ObjectManager($this))->getObject(Sso::class, [
            'context' => $this->createStub(Context::class),
            'config' => $config,
            'activeProviderResolver' => $resolver,
            'backendUrl' => $this->createStub(BackendUrlInterface::class),
        ]);
    }

    private function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'https://idp.example',
            'https://idp.example/authorize',
            'https://idp.example/token',
            'https://idp.example/jwks'
        );
    }

    private function starter(
        Config $config,
        ActiveProviderResolver $resolver,
        AuthorizationStateStorageInterface $stateStorage,
        ProviderMetadata $metadata
    ): AuthorizationStarter {
        $discovery = $this->createStub(DiscoveryClient::class);
        $discovery->method('discover')->willReturn($metadata);

        $backendUrl = $this->createStub(BackendUrlInterface::class);
        $backendUrl->method('getUrl')->willReturn(self::CALLBACK_URL);

        return new AuthorizationStarter(
            $config,
            $resolver,
            $discovery,
            new AuthorizationRequestFactory(new Random()),
            $stateStorage,
            $backendUrl
        );
    }

    private function callbackHandler(
        Config $config,
        ActiveProviderResolver $resolver,
        AuthorizationStateStorageInterface $stateStorage,
        ProviderMetadata $metadata
    ): CallbackHandler {
        $discovery = $this->createStub(DiscoveryClient::class);
        $discovery->method('discover')->willReturn($metadata);

        $tokenClient = $this->createStub(TokenClient::class);
        $tokenClient->method('exchangeCode')->willReturn(new TokenResponse('the-id-token'));

        $jwksClient = $this->createStub(JwksClient::class);
        $jwksClient->method('getKeySet')->willReturn(new JWKSet([]));

        $validator = $this->createStub(IdTokenValidator::class);
        $validator->method('validate')->willReturn([
            'sub' => self::SUBJECT,
            'email' => self::EMAIL,
            'name' => 'Jane Doe',
            'groups' => ['admins'],
        ]);

        $backendUrl = $this->createStub(BackendUrlInterface::class);
        $backendUrl->method('getUrl')->willReturn(self::CALLBACK_URL);

        return new CallbackHandler(
            $config,
            $resolver,
            $discovery,
            $tokenClient,
            $jwksClient,
            $validator,
            new IdentityFactory(),
            $stateStorage,
            $backendUrl
        );
    }

    /**
     * Provisioner whose lookups miss (no existing admin), so a new user is created.
     *
     * @param UserResource&\PHPUnit\Framework\MockObject\MockObject $userResource
     * @param User&\PHPUnit\Framework\MockObject\MockObject $newUser
     */
    private function provisioner(UserResource $userResource, User $newUser): UserProvisioner
    {
        $emptyUser = $this->createStub(User::class);
        $emptyUser->method('getId')->willReturn(null);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($emptyUser);

        $collectionFactory = $this->createStub(UserCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $userFactory = $this->createStub(UserFactory::class);
        $userFactory->method('create')->willReturn($newUser);

        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturn('RANDOMPASSWORD');

        return new UserProvisioner($userFactory, $userResource, $collectionFactory, $random);
    }

    /**
     * New admin user mock that records every setData call and reports no current role.
     *
     * @param array<int,mixed> $setDataCalls captured setData arguments, by reference
     * @return User&\PHPUnit\Framework\MockObject\Stub
     */
    /**
     * RoleFactory stub whose created role loads with the given id (i.e. the role
     * exists). RoleAssigner probes this before assigning.
     */
    private function roleFactory(string $roleId): RoleFactory
    {
        $role = $this->createStub(Role::class);
        $role->method('load')->willReturnSelf();
        $role->method('getId')->willReturn($roleId);

        $factory = $this->createStub(RoleFactory::class);
        $factory->method('create')->willReturn($role);

        return $factory;
    }

    private function newUser(array &$setDataCalls): User
    {
        $role = $this->createStub(Role::class);
        $role->method('getId')->willReturn(null);

        $user = $this->createStub(User::class);
        $user->method('getRole')->willReturn($role);
        // DataObject::setData is ($key, $value = null); the mock always passes both,
        // so each captured call is [$key, $value] — $key is the array on a bulk set.
        $user->method('setData')->willReturnCallback(
            function ($key, $value = null) use (&$setDataCalls, $user) {
                $setDataCalls[] = [$key, $value];
                return $user;
            }
        );

        return $user;
    }

    /**
     * Extract the `state` query param from an authorization URL.
     */
    private function stateFromUrl(string $url): string
    {
        $query = (string)parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        return (string)($params['state'] ?? '');
    }
}
