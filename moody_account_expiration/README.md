# Moody Account Expiration

Adds a private, date-only expiration field to user accounts. Drupal cron blocks
active accounts on its first run at or after the start of their expiration date
in the site's timezone. User 1 is always exempt.

Users can view their own expiration date but cannot change it. User 1 and users
with the unassigned `Account Expiration Administrator` role can view and edit
dates on other accounts. When this module blocked an account, clearing its date
or moving it into the future reactivates it. Accounts blocked by another process
remain blocked.

Run enforcement programmatically with:

```bash
drush moody-account-expiration:enforce
```

For local verification after enabling the module:

```bash
drush php:script web/modules/custom/moody_modules/moody_account_expiration/tests/account_expiration_smoke.php
```
