# NextMagentaCloud provisioning functions

## App configuration

|App parameter                        | Purpose                                                                               |
|-------------------------------------|---------------------------------------------------------------------------------------|
|nmcprovisioning userpreserveurl      | url to redirect user on "Telekom erhalten" process account                            |
|nmcprovisioning userwithdrawurl      | url to redirect user on general withdrawn account                                     |
|nmcprovisioning userotturl           | url to redirect OTT user directly into "Free" booking                                 |
|nmcprovisioning useraccessurl        | url to redirect Access user directly into "Magenta S" booking                         |
|nmcprovisioning userretention        | (optional override, TimeInterval) retention time !=60 days default (e.g. for test)    |
|[invalidated]   deletionjobtime      | (optional override, Time) Earliest time when daily deletion job should run (04:00Z)   | 

Remember that NextCloud app configuration values only support string, so 300sec is '300'.

The configuration could be done with the following commandline calls (only, no UI):
```
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userpreserveurl --value 'https://telekom.example.com/'
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userwithdrawurl --value "https://cloud.telekom-dienste.de/tarife"
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userotturl --value 'https://telekom.example.com/'
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning useraccessurl --value "https://cloud.telekom-dienste.de/tarife"
sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userretention --value "P60DT1H" <TimeInterval formatted P..T... value>
// not implemented atm: sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning deletionjobtime --value "HH:MM:SSZ"
```

## Running app unit tests
Before first run, prepare your app for unittesting by:
```
cd custom_apps/myapp

# run once if needed
composer install --no-dev -o
```

Execute unittests with the mandatory standard run before push:
```
phpunit --stderr --bootstrap tests/bootstrap.php tests/unit/MyTest.php
```

For quicker development (only!), you could skip large/long running tests
```
phpunit --stderr --bootstrap tests/bootstrap.php --exclude-group=large tests/unit/MyTest.php
```

Or you could limit your call to some methods only:
```
phpunit --stderr --bootstrap tests/bootstrap.php --filter='testMethod1|testMethod2' tests/unit/MyTest.php
```


## Tip for logfile filtering:
```
tail -f /var/log/nextcloud/nextcloud.json.log |jq 'select(.app=="nmcprovisioning")'
```

Only user_oidc and nmcprovisioning, without deprecation warnings:
```
tail -f /var/log/nextcloud/nextcloud.json.log |jq 'select(.app=="nmcprovisioning") | select(.message|contains("deprecated")|not)'
```

## calling composer
For building only
```
composer install --no-dev -o
```
If you want to check in `vendor/`dir, make sure to call composerin this mode and check in only the files
generated in non-dev mode!

For dev:
```
composer install --dev -o
```
Don´t check in the additionally pulled dev content!

## OBSOLETE: SLUP SOAP test calls
It is recommended to implement unittests or integration tests instead.

```
curl -i -X POST -H "Content-Type: application/soap+xml" \
    -H 'SOAPAction: "http://slup2soap.idm.telekom.com/slupClient/SLUPConnect"' \
    -d '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:slupClient="http://slup2soap.idm.telekom.com/slupClient/"><SOAP-ENV:Body><slupClient:SLUPConnect><token>0</token></slupClient:SLUPConnect></SOAP-ENV:Body></SOAP-ENV:Envelope>' http://localhost:8080/index.php/apps/nmcprovisioning/api/1.0/slup
```

curl -i -X POST -H "Content-Type: application/soap+xml" \
    -H 'SOAPAction: "http://slup2soap.idm.telekom.com/slupClient/SLUPDisconnect"' \
-d '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:slupClient="http://slup2soap.idm.telekom.com/slupClient/"><SOAP-ENV:Body><slupClient:SLUPDisconnect><token>0</token></slupClient:SLUPDisconnect></SOAP-ENV:Body></SOAP-ENV:Envelope>' http://localhost:8080/index.php/apps/nmcprovisioning/api/1.0/slup


oc_preferences

+-------------+-------------+------+-----+---------+-------+
| Field       | Type        | Null | Key | Default | Extra |
+-------------+-------------+------+-----+---------+-------+
| userid      | varchar(64) | NO   | PRI |         |       |
| appid       | varchar(32) | NO   | PRI |         |       |
| configkey   | varchar(64) | NO   | PRI |         |       |
| configvalue | longtext    | YES  |     | NULL    |       |
+-------------+-------------+------+-----+---------+-------+

