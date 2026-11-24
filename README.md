# NextMagentaCloud provisioning functions

The `nmcprovisioning` app contains MagentaCLOUD-specific business logic for user provisioning based on Telekom/OIDC account information.

Main responsibilities:

- evaluate account and tariff claims
- determine quota and tariff group
- create and update Nextcloud users
- update display name, email, quota and enabled state
- manage tariff group assignments
- handle withdrawn, locked and reactivated accounts
- redirect users to the appropriate booking/preserve flow
- migrate existing users to provisioning groups
- schedule and execute delayed user deletion

## App configuration

|App parameter                        | Purpose                                                                               |
|-------------------------------------|---------------------------------------------------------------------------------------|
| `nmcprovisioning userpreserveurl`   | Redirect for "Telekom erhalten" / preserve-account flow                               |
| `nmcprovisioning userwithdrawurl`   | Redirect for generally withdrawn accounts                                             |
| `nmcprovisioning userotturl`        | Redirect OTT users into "Free" booking                                                |
| `nmcprovisioning useraccessurl`     | Redirect Access users into "Magenta S" booking                                        |
| `nmcprovisioning userretention`     | Optional ISO 8601 deletion retention override, default `P60D`                         |

Configuration is done using `occ`:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userpreserveurl --value="https://telekom.example.com/"
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userwithdrawurl --value="https://cloud.telekom-dienste.de/tarife"
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userotturl --value="https://telekom.example.com/"
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning useraccessurl --value="https://cloud.telekom-dienste.de/tarife"
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userretention --value="P60D"
```

For short integration tests the retention can for example be set to five minutes:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userretention --value="PT5M"
```

Remove the override to use the default `P60D` again:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:delete nmcprovisioning userretention
```

## Account withdrawal and deletion

Withdrawn users are disabled first and deleted only after the configured retention period.

The app stores:

- `withdrawal` – accepted withdrawal timestamp
- `deletion` – planned deletion timestamp
- `last_account_change` – newest accepted account-state change

A reactivation removes the pending withdrawal/deletion state but keeps `last_account_change` to reject delayed older events.

Withdrawals without a valid authoritative `changeTime` do not create or overwrite a deletion schedule.

Repeated or stale events do not move an existing deletion date.

## Provisioning groups

Quota changes automatically update the user's MagentaCLOUD provisioning group.

The app also provides commands to create and migrate provisioning groups:

```bash
sudo -u www-data php /var/www/nextcloud/occ app:generate_new_provisioning_groups
sudo -u www-data php /var/www/nextcloud/occ app:migrate_provisioning_groups
```

Provisioning groups are also integrated with the configured Collabora/richdocuments group permissions.

## Running unit tests

Install dependencies if required:

```bash
cd custom_apps/nmcprovisioning
composer install
```

Run the unit tests:

```bash
phpunit --stderr --bootstrap tests/bootstrap.php tests/unit
```

Run a specific test:

```bash
phpunit --stderr --bootstrap tests/bootstrap.php tests/unit/UserAccountDeletionJobTest.php
```

or filter individual methods:

```bash
phpunit --stderr --bootstrap tests/bootstrap.php --filter='testMethod1|testMethod2' tests/unit
```
