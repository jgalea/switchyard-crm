# Setup

## Connecting

Conduit CRM → Connection. Pick your CRM, save, then fill in the credential
fields that appear. Each provider asks for what it actually needs.

Stored API keys are never rendered back into the page. Leaving a password field
blank keeps the saved value, so you can change an unrelated setting without
re-entering a key.

| CRM | Needs |
|---|---|
| ActiveCampaign | API URL, API key. Tracking ID and event key are optional. |
| HubSpot | Private app token with CRM contacts and lists scopes. Portal ID for tracking. |
| Mailchimp | API key and audience ID. The data centre is read from the key suffix. |
| Klaviyo | Private API key with profile and list scopes. Public key for tracking. |
| Kit | v4 API key. |
| Brevo | v3 API key. |
| FluentCRM | Nothing. It runs on the same site. |
| MailerLite | API token. |
| Zoho CRM | Client ID, secret, refresh token, and the data centre your account uses. |
| Salesforce | Consumer key, secret, refresh token, instance URL. |

## Field mapping

Conduit CRM → Sync. The left column is every WordPress field available,
including fields contributed by active integrations such as WooCommerce billing
details. Pick the CRM field to send it to and tick Sync.

Types are coerced on the way out: dates become ISO-8601, checkboxes become
booleans, multi-selects stay arrays where the CRM supports them. A CRM that
rejects a malformed date will otherwise fail the whole write.

## Staging sites

Turn on staging mode under Sync when you clone a production site. Every outbound
write is blocked and logged instead, so a staging copy cannot mutate live CRM
records. There is also a `conduit_staging_mode` filter if you would rather set it
from `wp-config.php`.

## Webhooks

The Connection screen shows a webhook URL carrying a shared secret. Point your
CRM's automation at it to push tag changes back into WordPress.

The listener does not trust tags in the request body. It re-reads them from the
API, so a replayed or forged request cannot grant access.

Rotate the secret from the same screen if it leaks; remember to update the CRM.

## Batch operations

Conduit CRM → Tools.

- Refresh tags and fields from the CRM — clears the cached catalogues.
- Resync all users — queues one job per user.
- Pull tags for all users — refreshes every cached tag set.

Large sites should use WP-CLI instead, which runs inline and reports progress.
