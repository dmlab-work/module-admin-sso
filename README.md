# DmLab_AdminSso

> Provider-agnostic single sign-on for the Magento 2 admin panel (OIDC).

[![CI](https://github.com/dmlab-work/module-admin-sso/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-admin-sso/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-admin-sso/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-admin-sso) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-admin-sso)](https://packagist.org/packages/dmlab/module-admin-sso) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-admin-sso)](https://packagist.org/packages/dmlab/module-admin-sso) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-admin-sso/php)](https://packagist.org/packages/dmlab/module-admin-sso) [![License](https://img.shields.io/packagist/l/dmlab/module-admin-sso)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

The admin-login **capability core**: it logs staff into the Magento admin backend over OIDC, but never references a concrete IdP. Install a provider plugin (`admin-sso-okta`, `admin-sso-azure`, …) that supplies the IdP; the plugin pulls this core, which in turn pulls the shared OIDC engine `sso-core`.

## Features

- **JIT admin-user creation** — matches an admin user by IdP issuer + `sub` (or IdP-verified email), creating one on first login.
- **IdP-group → ACL-role mapping** — assigns Magento roles from IdP groups on every sign-in.
- **Enforce SSO + break-glass** — block native password login (form and REST token) except for an explicit username allowlist.
- **2FA interplay** — an SSO login whose ID token proves MFA (`amr`/`acr`) satisfies Magento core 2FA, avoiding a double prompt.
- **Provider-agnostic** — one core drives any OIDC IdP through a provider preset.

## Installation

Normally installed via a provider plugin (e.g. `admin-sso-okta`), which pulls this core and `sso-core`:

```bash
composer require dmlab/module-admin-sso-okta
bin/magento module:enable DmLab_SsoCore DmLab_AdminSso DmLab_AdminSsoOkta
bin/magento setup:upgrade
```

Direct install of the core only:

```bash
composer require dmlab/module-admin-sso
bin/magento module:enable DmLab_SsoCore DmLab_AdminSso
bin/magento setup:upgrade
```

Register the callback URL in your IdP: `https://<admin-host>/<admin-path>/adminsso/sso/callback`, where `<admin-path>` is the backend frontName (default `admin`). It must exactly match the admin URL used at runtime.

## Configuration

Admin → Stores → Configuration → **DMLab → Admin SSO → General** (config path `dmlab_admin_sso/general/*`).

| Field | Path | Notes |
|---|---|---|
| Enable Admin SSO | `enabled` | Master switch; off by default. |
| Identity Provider | `active_provider` | Dropdown populated by installed provider plugins. |
| Client ID | `client_id` | OIDC client id from the IdP. |
| Client Secret | `client_secret` | Stored encrypted; sensitive, so `app:config:dump` skips it (set per environment with `config:sensitive:set`). |
| Enforce SSO | `enforce_sso` | Blocks native password login. See below. |
| Allow Break-Glass Login | `break_glass` | Lets allowlisted users keep password login where SSO blocks it. Off by default. |
| Break-Glass Usernames | `break_glass_usernames` | Admin usernames, one per line or comma-separated; case-insensitive. |
| Group to Role Map | `group_role_map` | IdP group → ACL role rules. See below. |
| Default Role | `default_role` | Fallback role when no group matches; empty = refuse login. |
| Trust IdP MFA | `trust_idp_mfa` | On by default; skips Magento 2FA only with MFA evidence. See below. |
| Trusted ACR Values | `trusted_acr_values` | Optional `acr` values that count as MFA evidence. |

A "Sign in with SSO" button appears on the admin login page when the module is enabled and a provider is selected, using the active preset's branding.

### Group → role mapping

`group_role_map` takes one rule per line as `idp_group=role_id`, where `role_id` is a Magento admin role id (`authorization_role` with `role_type` G). Blank lines and `#` comments are ignored. A Magento admin holds one role, so the first line matching any of the user's groups wins (rule order, not IdP group order); a repeated group keeps its first line.

```
# IdP group          Magento role id
admins=1
support-team=4
```

Roles are (re)assigned on every login, so IdP group changes take effect at next sign-in. An identity whose groups match no rule gets `default_role`. If that is empty (or the role id does not exist) the SSO login is refused before any admin is created or linked; the stored role of an existing admin is never demoted — deprovisioning is SCIM's job. If the IdP sends the groups as a distributed claim (`_claim_names`, e.g. Entra groups overage), the groups are incomplete and the login is refused too.

## Enforce SSO + break-glass

With **Enforce SSO** on, every native password login is rejected — the admin form and `POST /rest/V1/integration/admin/token` alike (enforced on `Magento\User\Model\User::authenticate()`). All sign-in goes through the IdP.

Even with Enforce SSO off, an admin linked to an IdP identity (created or adopted by SSO) cannot use a password while SSO is enabled, so a local password (e.g. from "Forgot password") does not outlive deactivation at the IdP. Local-only admins keep password login.

For recovery if the IdP is misconfigured, turn on **Allow Break-Glass Login** and list the local admins in **Break-Glass Usernames**. Only those usernames keep password login; an empty list means no bypass. There is no URL parameter.

With break-glass off, a lockout is recoverable only by disabling `enforce_sso` via CLI/DB:

```bash
bin/magento config:set dmlab_admin_sso/general/enforce_sso 0
```

## 2FA

With `Magento_TwoFactorAuth` enabled, an SSO login skips Magento 2FA only when **Trust IdP MFA** is on and the validated ID token carries MFA evidence:

- `amr` (RFC 8176) contains `mfa` or `mca`, or a possession/biometric factor (`otp`, `hwk`, `swk`, `sms`, `tel`, `sc`, `fpt`, `face`, …) plus another method (e.g. `["pwd","otp"]`); Okta and Entra send `mfa` after an MFA challenge;
- or `acr` equals one of **Trusted ACR Values**.

Otherwise (no `amr`/`acr`, e.g. Google, or password-only) Magento 2FA still applies. Trust IdP MFA = No always keeps Magento 2FA.

## How it works

1. User clicks "Sign in with SSO" → `adminsso/sso/start` builds the OIDC auth URL (state + nonce + PKCE; pending state valid 10 minutes, at most 10 per session) via sso-core and the active preset, then redirects to the IdP.
2. The IdP redirects back to `adminsso/sso/callback`, which validates `state` and the response issuer (RFC 9207 `iss`), exchanges the code, and normalizes claims into an `Identity` via sso-core.
3. The ACL role is resolved from IdP groups; if none resolves, the login is refused here.
4. JIT: the admin user is matched by IdP issuer + `sub` (stored on `admin_user.dmlab_sso_issuer` / `dmlab_sso_subject_id`, unique together, added at `setup:upgrade`), falling back to email — adopted only when the IdP marks it verified (`email_verified`), otherwise refused — and created if absent (with a suffixed username on any local collision). A matched admin must be active and not locked (`lock_expires`). The role is assigned and the admin backend session is established like a native login: `backend_auth_user_login_success` fires and `Magento_Security` records the session (Admin Account Sharing applies).
5. If the IdP proved MFA, the session satisfies Magento core 2FA (`Magento_TwoFactorAuth`); otherwise the user completes Magento 2FA.

### Identity links and switching IdP

An admin is linked to one IdP identity (issuer + `sub`). Links created before the issuer was stored are bound to the issuer of their next SSO login. A different issuer (new IdP, tenant or authorization server) never matches an existing link: its users go through the normal email adoption / JIT rules, and email adoption only applies to unlinked admins. To move linked admins to a new IdP, clear their link first:

```sql
UPDATE admin_user SET dmlab_sso_subject_id = NULL, dmlab_sso_issuer = NULL WHERE user_id IN (...);
```

## Requirements

- Magento Open Source / Adobe Commerce **2.4.x** (tested on 2.4.9)
- PHP **8.3 – 8.5**

## Part of the DMLab identity suite

| Repo | Role |
|------|------|
| `sso-core` | Shared OIDC engine (installed automatically) |
| `admin-sso` · `admin-sso-<idp>` | Admin-panel SSO login |
| `customer-sso` · `customer-sso-<idp>` | Storefront SSO login |
| `admin-scim` · `admin-scim-<idp>` | Admin-user provisioning (SCIM 2.0) |

## License

[OSL-3.0](LICENSE) © DMLab. Commercial licensing and support: <https://dmlab.work>.
