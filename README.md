# DmLab_AdminSso

> Provider-agnostic single sign-on for the Magento 2 admin panel (OIDC).

[![CI](https://github.com/dmlab-work/module-admin-sso/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-admin-sso/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-admin-sso/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-admin-sso) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-admin-sso)](https://packagist.org/packages/dmlab/module-admin-sso) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-admin-sso)](https://packagist.org/packages/dmlab/module-admin-sso) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-admin-sso/php)](https://packagist.org/packages/dmlab/module-admin-sso) [![License](https://img.shields.io/packagist/l/dmlab/module-admin-sso)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

The admin-login **capability core**: it logs staff into the Magento admin backend over OIDC, but never references a concrete IdP. Install a provider plugin (`admin-sso-okta`, `admin-sso-azure`, …) that supplies the IdP; the plugin pulls this core, which in turn pulls the shared OIDC engine `sso-core`.

## Features

- **JIT admin-user creation** — matches an admin user by IdP `sub` (or IdP-verified email), creating one on first login.
- **IdP-group → ACL-role mapping** — assigns Magento roles from IdP groups on every sign-in.
- **Enforce SSO + break-glass** — block native password login (form and REST token) except for an explicit username allowlist.
- **2FA interplay** — an IdP-authenticated session satisfies Magento core 2FA, avoiding a double prompt.
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
| Allow Break-Glass Login | `break_glass` | Lets allowlisted users bypass Enforce SSO. Off by default. |
| Break-Glass Usernames | `break_glass_usernames` | Admin usernames, one per line or comma-separated; case-insensitive. |
| Group to Role Map | `group_role_map` | IdP group → ACL role rules. See below. |
| Default Role | `default_role` | Fallback role when no group matches; empty = refuse login. |

A "Sign in with SSO" button appears on the admin login page when the module is enabled and a provider is selected, using the active preset's branding.

### Group → role mapping

`group_role_map` takes one rule per line as `idp_group=role_id`, where `role_id` is a Magento `authorization_role` id. Blank lines and `#` comments are ignored; on duplicate groups the later line wins.

```
# IdP group          Magento role id
admins=1
support-team=4
```

Roles are (re)assigned on every login, so IdP group changes take effect at next sign-in. An identity whose groups match no rule gets `default_role`. If that is empty (or the role id does not exist) the SSO login is refused before any admin is created or linked; the stored role of an existing admin is never demoted — deprovisioning is SCIM's job.

## Enforce SSO + break-glass

With **Enforce SSO** on, every native password login is rejected — the admin form and `POST /rest/V1/integration/admin/token` alike (enforced on `Magento\User\Model\User::authenticate()`). All sign-in goes through the IdP.

For recovery if the IdP is misconfigured, turn on **Allow Break-Glass Login** and list the local admins in **Break-Glass Usernames**. Only those usernames keep password login; an empty list means no bypass. There is no URL parameter.

With break-glass off, a lockout is recoverable only by disabling `enforce_sso` via CLI/DB:

```bash
bin/magento config:set dmlab_admin_sso/general/enforce_sso 0
```

## How it works

1. User clicks "Sign in with SSO" → `adminsso/sso/start` builds the OIDC auth URL (state + nonce + PKCE) via sso-core and the active preset, then redirects to the IdP.
2. The IdP redirects back to `adminsso/sso/callback`, which validates `state` and the response issuer (RFC 9207 `iss`), exchanges the code, and normalizes claims into an `Identity` via sso-core.
3. The ACL role is resolved from IdP groups; if none resolves, the login is refused here.
4. JIT: the admin user is matched by IdP `sub` (stored on a unique `admin_user.dmlab_sso_subject_id` column added at `setup:upgrade`), falling back to email — adopted only when the IdP marks it verified (`email_verified`), otherwise refused — and created if absent (with a suffixed username on any local collision). The role is assigned and the admin backend session is established.
5. The IdP-authenticated session satisfies Magento core 2FA (`Magento_TwoFactorAuth`), so users are not double-prompted.

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
