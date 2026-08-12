<div align="center">

# Switchyard CRM

[![Docs](https://img.shields.io/badge/DOCS-read%20the%20guide-F5A623?style=for-the-badge)](docs/README.md)
[![License](https://img.shields.io/badge/LICENSE-GPLv3-5C9E31?style=for-the-badge)](LICENSE)
[![Built by](https://img.shields.io/badge/BUILT%20BY-JEAN%20GALEA-8A2BE2?style=for-the-badge)](https://jeangalea.com)

**Connect WordPress to your CRM. Sync users, purchases and form entries, and gate content with CRM tags.**

</div>

Ten CRMs, one model. When someone registers, buys or submits a form, their record lands in your CRM with the fields you mapped. When you tag them in the CRM, that tag decides what they can see on the site.

## What it does

Outward: user registration and profile changes, WooCommerce orders including guest checkout, Easy Digital Downloads purchases and refunds, and submissions from Gravity Forms, WPForms, Fluent Forms and Contact Form 7 with per-form field mapping.

Back inward: tags via an inbound webhook, so a CRM automation can grant or revoke access; any mapped field on demand; and contacts imported as WordPress users.

Access control: restrict any post type or taxonomy term by tag. Restricted content is filtered out of archives, search, feeds and the REST API, not merely hidden on the single view. Menu items, widgets and blocks get their own visibility rules.

## Supported CRMs

ActiveCampaign, HubSpot, Mailchimp, Klaviyo, Kit, Brevo, FluentCRM, MailerLite, Zoho CRM, Salesforce.

Providers declare capabilities rather than implementing one fat interface, because CRMs genuinely differ. ActiveCampaign has tags and lists; Mailchimp has audiences and tags addressed by name; Klaviyo has profiles, lists and segments and no profile tags at all; Salesforce has none of them and uses a multi-select picklist. A single adapter layer presents one segmentation model on top, so tag-based access control works on every provider.

## Reliability

A CRM outage must never break checkout or registration. Every outbound call goes through one HTTP client with timeouts, exponential backoff honouring `Retry-After`, and a per-provider circuit breaker that suspends calls to a CRM that has stopped responding rather than adding a timeout to every page load. Sync runs in the background through Action Scheduler. Integration callbacks are wrapped so nothing thrown inside them reaches the host plugin.

Everything is logged with credentials redacted, so a support conversation starts from evidence rather than guesswork.

## Installing

```bash
git clone https://github.com/jgalea/switchyard-crm.git wp-content/plugins/switchyard-crm
cd wp-content/plugins/switchyard-crm
composer install --no-dev
```

Activate it, then go to Switchyard CRM → Connection.

## Developer API

```php
switchyard_apply_tags( array( 'Customer' ) );          // current user
switchyard_apply_tags( array( 'VIP' ), $user_id );
switchyard_remove_tags( array( 'Trial' ), $user_id );

if ( switchyard_has_tag( 'Customer' ) ) { /* … */ }

switchyard_sync_user( $user_id );                       // push now, skip the queue
switchyard_sync_contact( 'person@example.com', $fields, array( 'Lead' ) );
switchyard_can_access( $post_id, $user_id );
```

Tags may be passed as identifiers or as labels; labels are resolved against the CRM and created when they do not exist.

Filters exist on the sync payload (`switchyard_sync_fields`), the access decision (`switchyard_can_access`), form field extraction (`switchyard_form_fields`), HTTP arguments (`switchyard_http_args`) and more. See [the developer guide](docs/developers.md).

## WP-CLI

```bash
wp switchyard status
wp switchyard sync 42
wp switchyard sync                      # everyone, queued
wp switchyard tags 42 --refresh
wp switchyard import --tag=12 --role=subscriber
```

## Adding a CRM

Implement `Switchyard\Crm\Contracts\Provider` plus whichever capability interfaces the CRM supports, then add a test extending `ProviderContractTestCase` with a fixture map. The contract suite proves the new provider behaves like the existing ten. See [adding a provider](docs/adding-a-provider.md).

## Development

```bash
composer install
composer test        # phpunit
composer lint        # phpcs, WordPress-Extra
composer analyse     # phpstan level 6
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

Switchyard CRM is an independent project. It is not affiliated with, endorsed by, or derived from any other CRM integration plugin, and the CRM names above are trademarks of their respective owners.
