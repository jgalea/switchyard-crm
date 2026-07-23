# Developer guide

## Functions

| Function | Purpose |
|---|---|
| `conduit_apply_tags( array $tags, ?int $user_id = null )` | Apply tags. IDs or labels; labels are resolved and created if missing. |
| `conduit_remove_tags( array $tags, ?int $user_id = null )` | Remove tags. |
| `conduit_has_tag( string $tag, ?int $user_id = null )` | Read from the cache. Accepts an ID or a label. |
| `conduit_get_tags( ?int $user_id = null )` | Cached tag IDs. |
| `conduit_refresh_tags( ?int $user_id = null )` | Pull the authoritative set from the CRM. |
| `conduit_sync_user( ?int $user_id, array $overrides = [] )` | Push now, skipping the queue. Returns the contact ID. |
| `conduit_sync_contact( string $email, array $fields, array $tags )` | Create or update a contact with no WordPress user. |
| `conduit_get_contact_id( ?int $user_id, bool $refresh = false )` | Resolve the CRM contact ID. |
| `conduit_can_access( int $post_id, ?int $user_id = null )` | The access decision. |
| `conduit_available_tags()` | Every tag in the CRM, for building a UI. |
| `conduit_get_provider()` | The connected provider, or null. |

Nothing here throws. A disconnected CRM makes writes return `false` and reads
return empty.

## Filters

| Filter | Use |
|---|---|
| `conduit_sync_fields` | Alter the payload before it is sent. |
| `conduit_wp_fields` | Add mappable WordPress fields. This is how integrations contribute. |
| `conduit_user_tags` | Alter the tag set access control sees. |
| `conduit_can_access` | Override the access decision. |
| `conduit_restricted_message` | Change the message per post. |
| `conduit_gated_post_types` | Limit which post types are gateable. |
| `conduit_filter_query` | Skip query filtering for a specific query. |
| `conduit_should_sync_user` | Skip syncing a user. |
| `conduit_defer_sync` | Force synchronous sync. |
| `conduit_staging_mode` | Block all writes. |
| `conduit_http_args` | Alter request arguments. |
| `conduit_http_timeout` | Per-provider timeout. |
| `conduit_form_fields`, `conduit_form_tags` | Alter what a form submission sends. |
| `conduit_woocommerce_order_tags`, `conduit_edd_payment_tags` | Alter purchase tags. |
| `conduit_enable_integration` | Disable an integration. |
| `conduit_klaviyo_revision` | Pin a different Klaviyo API revision. |

## Actions

| Action | Fires |
|---|---|
| `conduit_loaded` | Plugin booted. |
| `conduit_register_features` | Companion plugins register capabilities. |
| `conduit_register_integrations` | Add an integration to the registry. |
| `conduit_contact_created` / `conduit_contact_updated` | After a write. |
| `conduit_tags_updated` | The cached tag set changed. |
| `conduit_order_synced` | An order was synced. Receives a normalised `Order`. |
| `conduit_order_refunded` | An order was refunded. |
| `conduit_form_submitted` | A form was processed. |
| `conduit_webhook_received` | A CRM webhook was handled. |
| `conduit_access_denied` | Someone was refused a post. |
| `conduit_contact_imported` | A contact became a user. |

## Order objects

`conduit_order_synced` receives a `Conduit\Crm\Order`, which is store-agnostic:
WooCommerce and Easy Digital Downloads produce the same shape, so a listener
only learns one.

```php
add_action( 'conduit_order_synced', function ( Conduit\Crm\Order $order, int $user_id ): void {
    foreach ( $order->lines as $line ) {
        // $line->product_id, $line->name, $line->quantity, $line->price
    }
}, 10, 2 );
```

## Calling a provider directly

Providers expose the full CRM surface, including endpoints the free plugin does
not wire a feature to. Check the capability first.

```php
$provider = conduit_get_provider();

if ( $provider instanceof Conduit\Crm\Contracts\SupportsEvents ) {
    $provider->track_event( $contact_id, 'Course Completed', array( 'course' => 'Intro' ) );
}
```

Capability interfaces: `SupportsTags`, `SupportsLists`, `SupportsTracking`,
`SupportsEcommerce`, `SupportsEvents`, `SupportsWebhooks`,
`SupportsContactImport`.

Provider methods throw `Conduit\Crm\Exception\CrmException` subclasses. Catch
them; do not let one reach a checkout hook.

## WP-CLI

```bash
wp conduit status
wp conduit sync <user>
wp conduit sync
wp conduit tags <user> [--refresh]
wp conduit import [--tag=<id>] [--role=<role>]
```
