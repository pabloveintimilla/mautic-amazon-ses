# Mautic 5 Amazon SES

<p style="text-align: center;">
<img src="Assets/img/icon.png" alt="Amazon SES" width="200"/>
</p>

This plugin enable Mautic 5 to run AWS SES as a email transport and provide a callback to process bounces.
Tested in Mautic 5.0.0 to 5.2.0

## INSTALLATION

1. Get the plugin using

```
composer require pabloveintimilla/mautic-amazon-ses
```

2. Clear cache

```
php bin/console cache:clear
```

3. Install plugin

```
php bin/console mautic:plugins:reload
```

## CONFIGURATION MAUTIC

Be sure to use the `ses+api` as Data Source Name (DSN).
The following is the example for the DSN.
`ses+api://ACCESS_KEY:SECRET_KEY@default?region=REGION`

Follow the steps to setup plugin DSN:

1. Navigate to Configuration > Mail Send Settings
2. Update the following fields leaving rest default or empty,

| Field    | Value         |
| -------- | ------------- |
| Scheme   | `ses+api`     |
| Host     | `default`     |
| Port     | `465`         |
| User     | `<aws-user>`  |
| Password | `<secretKey>` |
| Region   | `<region>`    |

The `<apiKey>` and `<secretKey>` will be a credential access from a user AWS.
The `<region>` is AWS region were run AWS SES in your account

## CONFIGURATION AWS

Process bounces you need to configure an AWS SNS to send a callback to Mautic.

1. Create a SNS topic attached to AWS SES Identity.
2. Configure a suscription:
   - Protocol: HTTPS
   - **Enable raw message delivery** 
   - Endpoint: `URL_MAUTIC`/mailer/callback.
4. Confirm SNS suscription, this plugin automatic activate.

## AUTHOR

👤 **Pablo Veintimilla**

- Twitter: [@pabloveintimilla](https://twitter.com/pabloveintimilla)
- Github: [@pabloveintimilla](https://github.com/pabloveintimilla)

[MailjetGuidePage]: https://dev.mailjet.com/email/guides/getting-started/
