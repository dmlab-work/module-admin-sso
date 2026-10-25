# Changelog

## 0.2.1

- Fixed: HTTP 500 ("Password is required field") on every admin page after a new admin's first SSO login.
- Tested on Magento 2.4.9 and PHP 8.5.

## 0.2.0

- **Break-glass is off by default** and limited to an explicit username allowlist (`break_glass_usernames`). Installs relying on the old default (on) now have it off: set Allow Break-Glass Login = Yes and list usernames to keep a recovery path. Installs that saved it as on lose the bypass until usernames are listed.
- **`?break_glass=1` request param removed.**
- Enforce SSO now also blocks the REST admin-token endpoint (`POST /V1/integration/admin/token`).
- Email adoption of an existing admin requires an IdP-verified email; unverified → login refused.
- **No role resolves → SSO login refused, role never demoted** (previously `role_id` was reset to 0). A mapped or default role id that does not exist also refuses the login. No admin is created or linked on refusal.
- Requires `dmlab/module-sso-core ^0.2`; provider plugins must require `dmlab/module-admin-sso ^0.2`.
- Code: `RoleAssigner::assign()` takes a role id (call `resolve()` first); `EnforceSso` plugs `User::authenticate()` instead of `Auth::login()`; `EnforceSso::BREAK_GLASS_PARAM` removed.
- Client secret marked sensitive (kept out of `app:config:dump`).
- Provider labels are translatable.
- Honours optional preset capabilities: extra authorization params, discovery issuer policy; JWKS refetch on key rotation.
- Packaging: Magento module requirements declared, `Magento_TwoFactorAuth` suggested, OSL-3.0 headers, i18n phrases.

## 0.1.1

- Rebrand to the `dmlab` vendor and `DmLab` namespace.
