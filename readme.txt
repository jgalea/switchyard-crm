=== Conduit CRM ===
Contributors: jeangalea
Tags: crm, marketing automation, activecampaign, hubspot, mailchimp
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connect WordPress to your CRM. Sync users, purchases and form entries, and gate content with CRM tags.

== Description ==

Conduit CRM keeps your WordPress site and your CRM in step. When someone registers, buys something or submits a form, their record lands in your CRM with the fields you mapped. When you tag them in the CRM, that tag decides what they can see on the site.

Ten CRMs are supported, and the same features work on all of them.

**What syncs outward**

* User registration and profile changes
* WooCommerce orders, including guest checkout, with per-product and per-status tags
* Easy Digital Downloads purchases and refunds
* Gravity Forms, WPForms, Fluent Forms and Contact Form 7 submissions, with per-form field mapping

**What syncs back**

* Tags, through an inbound webhook, so a CRM automation can grant or revoke access
* Any mapped field, on demand or on a schedule
* Contacts imported as WordPress users

**Access control**

Restrict any post, page or custom post type by tag, or restrict a whole taxonomy term. Restricted content is kept out of archives, search, feeds and the REST API, not just hidden from the single view. Menu items, widgets and blocks have their own visibility rules, and there are shortcodes for conditional content inside a post.

**Supported CRMs**

ActiveCampaign, HubSpot, Mailchimp, Klaviyo, Kit (formerly ConvertKit), Brevo, FluentCRM, MailerLite, Zoho CRM, Salesforce.

CRMs that have tags use tags. CRMs that only have lists, audiences or groups use those instead, so tag-based access control works everywhere.

**Built to stay out of the way**

A CRM outage never breaks checkout or registration. Requests retry with backoff, a circuit breaker suspends calls to a CRM that stops responding, and sync runs in the background. Everything is logged with credentials redacted, so a support conversation can start from evidence.

No nag screens, no upsell banners, no review prompts.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/conduit-crm` and activate it.
2. Go to Conduit CRM → Connection, pick your CRM and enter its credentials.
3. Map your fields under Sync.
4. Set access rules per post, or under Access for site-wide defaults.

== Frequently Asked Questions ==

= Does this work with a CRM that has no tags? =

Yes. Klaviyo, Brevo and MailerLite model segmentation as lists or groups rather than tags. Conduit uses whichever the CRM provides, and the settings screens use the CRM's own terminology.

= What happens if my CRM goes down? =

Nothing on the site breaks. Failed calls retry with backoff, then queue for later. After repeated failures Conduit stops calling that CRM for five minutes rather than adding a timeout to every page load. Access decisions use the cached tags in the meantime.

= Does it work with guest checkout? =

Yes. A WooCommerce or EDD order from someone without an account still creates or updates the contact, using the billing details on the order.

= Can I move to a different CRM later? =

Your field mapping and access rules are stored independently of the CRM, so switching means reconnecting and remapping fields. Tags have to exist in the new CRM under the same names.

= Is there a developer API? =

Yes. `conduit_apply_tags()`, `conduit_remove_tags()`, `conduit_has_tag()`, `conduit_sync_user()`, `conduit_sync_contact()` and more, plus filters on every sync payload and access decision. There are WP-CLI commands for status, sync, tags and import.

== Changelog ==

= 1.0.0 =
* First release.
* Ten CRM providers with a shared capability model.
* WooCommerce, Easy Digital Downloads, Gravity Forms, WPForms, Fluent Forms and Contact Form 7.
* Tag-based access control across single views, queries, feeds and REST.
* Inbound webhooks, batch import and export, WP-CLI commands.
