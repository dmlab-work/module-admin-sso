# MageDevGroup_AdminSso

> Provider-agnostic single sign-on for the Magento 2 admin panel (OIDC).

![License](https://img.shields.io/badge/license-OSL--3.0-green) ![Magento](https://img.shields.io/badge/Magento-2.4-orange) ![PHP](https://img.shields.io/badge/PHP-8.3--8.5-blue) ![Version](https://img.shields.io/badge/version-0.0.1-lightgrey)

The admin-login **capability core**: it logs staff into the Magento admin backend over OIDC, but never references a concrete IdP. Install a provider plugin (`admin-sso-okta`, `admin-sso-azure`, …) that supplies the IdP; the plugin pulls this core, which in turn pulls the shared OIDC engine `sso-core`.

## Features

- **JIT admin-user creation** — matches an admin user by IdP `sub` (or email), creating one on first login.
- **IdP-group → ACL-role mapping** — assigns Magento roles from IdP groups on every sign-in.
- **Enforce SSO + break-glass** — disable the native login form while keeping a guarded local-admin recovery path.
- **2FA interplay** — an IdP-authenticated session satisfies Magento core 2FA, avoiding a double prompt.
- **Provider-agnostic** — one core drives any OIDC IdP through a provider preset.

## Installation

Normally installed via a provider plugin (e.g. `admin-sso-okta`), which pulls this core and `sso-core`:

```bash
composer require magedevgroup/module-admin-sso-okta
bin/magento module:enable MageDevGroup_SsoCore MageDevGroup_AdminSso MageDevGroup_AdminSsoOkta
bin/magento setup:upgrade
```

Direct install of the core only:

```bash
composer require magedevgroup/module-admin-sso
bin/magento module:enable MageDevGroup_SsoCore MageDevGroup_AdminSso
bin/magento setup:upgrade
```

Register the callback URL in your IdP: `https://<admin-host>/<admin-path>/adminsso/sso/callback`, where `<admin-path>` is the backend frontName (default `admin`). It must exactly match the admin URL used at runtime.

## Configuration

Admin → Stores → Configuration → **MageDevGroup → Admin SSO → General** (config path `magedevgroup_admin_sso/general/*`).

| Field | Path | Notes |
|---|---|---|
| Enable Admin SSO | `enabled` | Master switch; off by default. |
| Identity Provider | `active_provider` | Dropdown populated by installed provider plugins. |
| Client ID | `client_id` | OIDC client id from the IdP. |
| Client Secret | `client_secret` | Stored encrypted. |
| Enforce SSO | `enforce_sso` | Disables the native login form. See below. |
| Allow Break-Glass Login | `break_glass` | Guarded local-admin path under enforce. On by default. |
| Group to Role Map | `group_role_map` | IdP group → ACL role rules. See below. |
| Default Role | `default_role` | Fallback role when no group matches; empty = deny. |

A "Sign in with SSO" button appears on the admin login page when the module is enabled and a provider is selected, using the active preset's branding.

### Group → role mapping

`group_role_map` takes one rule per line as `idp_group=role_id`, where `role_id` is a Magento `authorization_role` id. Blank lines and `#` comments are ignored; on duplicate groups the later line wins.

```
# IdP group          Magento role id
admins=1
support-team=4
```

Roles are (re)assigned on every login, so IdP group changes take effect at next sign-in. An identity whose groups match no rule gets `default_role`; if that is empty the user is denied a role.

## Enforce SSO + break-glass

With **Enforce SSO** on, the native username/password admin form is rejected — all sign-in goes through the IdP. To stay recoverable if the IdP is misconfigured, keep **Allow Break-Glass Login** on: a local admin can still sign in by adding `break_glass=1` to the login request:

```
https://<admin-host>/admin?break_glass=1
```

With break-glass off and enforce on, a lockout is not recoverable from the UI — only by disabling `enforce_sso` via CLI/DB. Leave break-glass on unless you have another recovery path.

## How it works

1. User clicks "Sign in with SSO" → `adminsso/sso/start` builds the OIDC auth URL (state + nonce + PKCE) via sso-core and the active preset, then redirects to the IdP.
2. The IdP redirects back to `adminsso/sso/callback`, which validates `state`, exchanges the code, and normalizes claims into an `Identity` via sso-core.
3. JIT: the admin user is matched by IdP `sub` (stored on a unique `admin_user.magedevgroup_sso_subject_id` column added at `setup:upgrade`), falling back to email, and created if absent (with a suffixed username on any local collision).
4. Roles are mapped from IdP groups and the admin backend session is established.
5. The IdP-authenticated session satisfies Magento core 2FA (`Magento_TwoFactorAuth`), so users are not double-prompted.

## Requirements

- Magento Open Source / Adobe Commerce **2.4.x**
- PHP **8.3 – 8.5**

## Part of the MageDevGroup identity suite

| Repo | Role |
|------|------|
| `sso-core` | Shared OIDC engine (installed automatically) |
| `admin-sso` · `admin-sso-<idp>` | Admin-panel SSO login |
| `customer-sso` · `customer-sso-<idp>` | Storefront SSO login |
| `admin-scim` · `admin-scim-<idp>` | Admin-user provisioning (SCIM 2.0) |

## License

[OSL-3.0](LICENSE) © MageDevGroup. Commercial licensing and support: <https://magedevgroup.com>.
