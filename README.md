## SilverStripe SendGrid Mailer

A drop-in solution to send all SilverStripe emails through SendGrid

## Installation

```
composer require unclecheese/silverstripe-sendgrid-mailer
```

## Environment Configuration

The mailer will activate once the following environment variable is set:

```
SENDGRID_API_KEY='mykey'
```

## Test emails

If you want to test SendGrid from a dev environment, you can force all emails
to a test address.

```
SENDGRID_TEST_EMAIL='myemail@example.com'
```