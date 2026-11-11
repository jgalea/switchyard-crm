=== Switchyard CRM Sync and Access ===
Contributors: jeangalea
Tags: crm, marketing automation, integration, sync, content restriction
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connect WordPress to your CRM. Sync users, purchases and form entries, and gate content with CRM tags.

== Description ==

Switchyard CRM keeps your WordPress site and your CRM in step. When someone registers, buys something or submits a form, their record lands in your CRM with the fields you mapped. When you tag them in the CRM, that tag decides what they can see on the site.

Ten CRMs are supported, and the same features work on all of them.

**What syncs outward**

* User registration and profile changes
* WooCommerce orders, including guest checkout, with per-product and per-status tags
* Easy Digital Downloads purchases and refunds
* Gravity Forms, WPForms, Fluent Forms and Contact Form 7 submissions, with per-form field mapping

**What syncs back**

* Tags, through an inbound webhook, so a CRM automation can grant or revoke access
* Any mapped field, when the CRM sends a webhook and on demand from the user profile or the Tools tab
* Contacts imported as WordPress users

**Access control**

Restrict any post, page or custom post type by tag, or restrict a whole taxonomy term from its edit screen. Restricted content is kept out of archives, search, feeds, comments and the REST API, not just hidden from the single view. Menu items and blocks have their own visibility rules, and there are shortcodes for conditional content inside a post.

**Supported CRMs**

ActiveCampaign, HubSpot, Mailchimp, Klaviyo, Kit (formerly ConvertKit), Brevo, FluentCRM, MailerLite, Zoho CRM, Salesforce.

CRMs that have tags use tags. CRMs that only have lists, audiences or groups use those instead, so tag-based access control works everywhere.

**Built to stay out of the way**

A CRM outage never breaks checkout or registration. Requests retry with backoff, a circuit breaker suspends calls to a CRM that stops responding, and sync runs in the background. Everything is logged with credentials redacted, so a support conversation can start from evidence.

No nag screens, no upsell banners, no review prompts.

== External services ==

Switchyard CRM only ever talks to the one CRM you connect it to. No service is contacted until you pick a CRM under Switchyard CRM → Connection and enter its credentials. Clearing those credentials stops all traffic to it.

What is sent: the contact data in your field map. That is always the email address, and then whatever user profile fields, order fields and form fields you have mapped. It is sent when someone registers or updates their profile, when a WooCommerce or Easy Digital Downloads order is placed or refunded, and when a mapped form is submitted. The plugin reads back tags and list membership when a user logs in and when the CRM sends a webhook, reads mapped fields on demand and on a webhook, and can import contacts as WordPress users.

Site tracking is a separate, off-by-default setting under Switchyard CRM → Sync. Turning it on loads the CRM's own visitor tracking script in your visitors' browsers, from that CRM's servers: js.hs-scripts.com for HubSpot, static.klaviyo.com for Klaviyo, diffuser-cdn.app-us1.com for ActiveCampaign. Those scripts see what the CRM's tracking normally sees, which is page views and, for a logged-in visitor, the email address Switchyard passes to identify them. Leave the setting off and none of it loads.

FluentCRM runs inside your own WordPress installation, so selecting it sends nothing off-site. The other providers are third-party services, each governed by its own terms and privacy policy:

* ActiveCampaign. Terms: https://www.activecampaign.com/legal/terms-of-service Privacy: https://www.activecampaign.com/legal/privacy-policy
* HubSpot. Terms: https://legal.hubspot.com/terms-of-service Privacy: https://legal.hubspot.com/privacy-policy
* Mailchimp. Terms: https://mailchimp.com/legal/terms/ Privacy: https://www.intuit.com/privacy/statement/
* Klaviyo. Terms: https://www.klaviyo.com/legal/terms-of-service Privacy: https://privacy.klaviyo.com/policies/?name=klaviyo-privacy-policy
* Kit. Terms: https://kit.com/terms Privacy: https://kit.com/privacy
* Brevo. Terms: https://www.brevo.com/legal/termsofuse/ Privacy: https://www.brevo.com/legal/privacypolicy/
* MailerLite. Terms: https://www.mailerlite.com/legal/terms-of-service Privacy: https://www.mailerlite.com/legal/privacy-policy
* Zoho CRM. Terms: https://www.zoho.com/terms.html Privacy: https://www.zoho.com/privacy.html
* Salesforce. Terms: https://www.salesforce.com/company/legal/ Privacy: https://www.salesforce.com/company/legal/privacy/

== Installation ==

1. Upload the plugin to `/wp-content/plugins/switchyard-crm` and activate it.
2. Go to Switchyard CRM → Connection, pick your CRM and enter its credentials.
3. Map your fields under Sync.
4. Set access rules per post, or under Access for site-wide defaults.

== Frequently Asked Questions ==

= Does this work with a CRM that has no tags? =

Yes. Klaviyo, Brevo and MailerLite model segmentation as lists or groups rather than tags. Switchyard uses whichever the CRM provides, and the settings screens use the CRM's own terminology.

= What happens if my CRM goes down? =

Nothing on the site breaks. Failed calls retry with backoff. After repeated failures Switchyard stops calling that CRM for five minutes rather than adding a timeout to every page load. Access decisions use the cached tags in the meantime.

= Does it work with guest checkout? =

Yes. A WooCommerce or EDD order from someone without an account still creates or updates the contact, using the billing details on the order.

= Can I move to a different CRM later? =

Your field mapping and access rules are stored independently of the CRM, so switching means reconnecting and remapping fields. Tags have to exist in the new CRM under the same names.

= Is there a developer API? =

Yes. `switchyard_apply_tags()`, `switchyard_remove_tags()`, `switchyard_has_tag()`, `switchyard_sync_user()`, `switchyard_sync_contact()` and more, plus filters on every sync payload and access decision. There are WP-CLI commands for status, sync, tags and import.

= How do I do something else with an order? =

Every synced WooCommerce or EDD order fires `switchyard_order_synced` with a normalised order object carrying line items, totals, currency and the customer user ID. Hook it to push orders wherever you want.

== Changelog ==

= Unreleased =
* New: settings for registration tags, WooCommerce and EDD purchase, status and refund tags, and per-form field mapping on the Integrations tab.
* New: block visibility for every block, and access rule fields on term edit screens and menu items.
* New: CRM panel and sync buttons on the user profile, and a Sync to CRM row action in the users list.
* New: webhooks pull mapped fields back onto the user, and a Tools action pulls them for everyone.
* New: personal-data export and erasure, and privacy policy text.
* New: log search, level filter, paging and a retention setting.
* Fixed: a term access rule no longer crashes archive pages, and term rules now apply to posts in any taxonomy.
* Fixed: a webhook can no longer link a user to a different CRM contact.
* Fixed: on multisite, contact IDs and tags are kept per site.
* Fixed: new contacts always get their email address, including guest form entries and registration tags.
* Fixed: comments on restricted posts are hidden from REST and feeds.
* Fixed: entries flagged as spam are not sent to the CRM.

= 1.1.0 =
* New: turn each integration, and each Pro module, on or off from the Integrations tab.
* New: a Switchyard purchase tags box on products and downloads, separate from the access tags. Tags you already apply on purchase are copied over when you update.
* Changed: the Webhook box and the Create users from webhooks setting only show for ActiveCampaign and Kit, the CRMs that send webhooks.
* Fixed: saving a product or post while the CRM can't be reached no longer clears its tags.

= 1.0.0 =
* First release.
* Ten CRM providers with a shared capability model.
* WooCommerce, Easy Digital Downloads, Gravity Forms, WPForms, Fluent Forms and Contact Form 7.
* Tag-based access control across single views, queries, feeds and REST.
* Inbound webhooks, batch import and export, WP-CLI commands.
