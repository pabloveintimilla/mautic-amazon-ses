# Mautic Amazon SES

![Amazons SES](Assets/img/icon.png "Amazons SES")

This plugin enable Mautic 5 to run AWS SES as a email transport and provide a callback to process bounces

## INSTALLATION

1. Get the plugin using `composer pablo.veintimilla/amazon-ses-bundle`
2. Install it using `php bin\console mautic:plugins:reload`.
3. The plugin will start listing on plugin page. ![Plugins Page](Docs/imgs/01%20-%20Plugins%20Page.png)

## CONFIGURATION

Be sure to use the `ses+api` as Data Source Name, or DSN.
The following is the example for the DSN.
`ses+api://ACCESS_KEY:SECRET_KEY@default?region=REGION&session_token=SESSION_TOKEN`

Follow the steps tp setup Mailjet SMTP DSN,

1. Navigate to Configuration (/s/config/edit>)
2. Scroll to Email Settings
3. Update the following fields leaving rest default or empty,

| Field    | Value         |
| -------- | ------------- |
| Scheme   | `ses+api`     |
| Host     | `default`     |
| Port     | `465`         |
| User     | `<apiKey>`    |
| Password | `<secretKey>` |

The `<apiKey>` and `<secretKey>` will be used for authentication purposes. Please visit the [Amazon SES Guide](https://aws.amazon.com/es/blogs/messaging-and-targeting/credentials-and-ses/)

On the Configuration page **Email DSN** should look like ![Email DSN](Assets/img/02%20-%20Email%20DSN.png "Email DSN")

## AUTHOR

👤 **Pablo Veintimilla**

- Twitter: [@\pabloveintimilla](https://twitter.com/pabloveintimilla)
- Github: [@pabloveintimilla](https://github.com/pabloveintimilla)

[MailjetGuidePage]: https://dev.mailjet.com/email/guides/getting-started/
