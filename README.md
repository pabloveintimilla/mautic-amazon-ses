# Mautic 5 Amazon SES

<p style="text-align: center;">
<img src="Assets/img/icon.png" alt="Amazon SES" width="200"/>
</p>

This plugin enable Mautic 5 to run AWS SES as a email transport and provide a callback to process bounces.

## INSTALLATION

1. Get the plugin using

```
composer require pabloveintimilla/mautic-amazon-ses
```

2. Install plugin

```
php bin\console mautic:plugins:reload
```

## CONFIGURATION MAUTIC

Be sure to use the `ses+api` as Data Source Name, or DSN.
The following is the example for the DSN.
`ses+api://ACCESS_KEY:SECRET_KEY@default?region=REGION`

Follow the steps tp setup Mailjet SMTP DSN:

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

- Configure a HTTP SNS topic to request to: `URL_MAUTIC`/mailer/callback
- Confirm SNS, this plugin automatic activate.

## AUTHOR

👤 **Pablo Veintimilla**

- Twitter: [@pabloveintimilla](https://twitter.com/pabloveintimilla)
- Github: [@pabloveintimilla](https://github.com/pabloveintimilla)

[MailjetGuidePage]: https://dev.mailjet.com/email/guides/getting-started/
