# Developer guide

## Functions

| Function | Purpose |
|---|---|
| `switchyard_apply_tags( array $tags, ?int $user_id = null )` | Apply tags. IDs or labels; labels are resolved and created if missing. |
| `switchyard_remove_tags( array $tags, ?int $user_id = null )` | Remove tags. |
| `switchyard_has_tag( string $tag, ?int $user_id = null )` | Read from the cache. Accepts an ID or a label. |
| `switchyard_get_tags( ?int $user_id = null )` | Cached tag IDs. |
| `switchyard_refresh_tags( ?int $user_id = null )` | Pull the authoritative set from the CRM. |
| `switchyard_sync_user( ?int $user_id, array $overrides = [] )` | Push now, skipping the queue. Returns the contact ID. |
| `switchyard_sync_contact( string $email, array $fields, array $tags )` | Create or update a contact with no WordPress user. |
| `switchyard_get_contact_id( ?int $user_id, bool $refresh = false )` | Resolve the CRM contact ID. |
| `switchyard_can_access( int $post_id, ?int $user_id = null )` | The access decision. |
| `switchyard_available_tags()` | Every tag in the CRM, for building a UI. |
| `switchyard_get_provider()` | The connected provider, or null. |

Nothing here throws. A disconnected CRM makes writes return `false` and reads
return empty.

## Filters

| Filter | Use |
|---|---|
| `switchyard_sync_fields` | Alter the payload before it is sent. |
| `switchyard_wp_fields` | Add mappable WordPress fields. This is how integrations contribute. |
| `switchyard_user_tags` | Alter the tag set access control sees. |
| `switchyard_can_access` | Override the access decision. |
| `switchyard_restricted_message` | Change the message per post. |
| `switchyard_gated_post_types` | Limit which post types are gateable. |
| `switchyard_filter_query` | Skip query filtering for a specific query. |
| `switchyard_should_sync_user` | Skip syncing a user. |
| `switchyard_defer_sync` | Force synchronous sync. |
| `switchyard_staging_mode` | Block all writes. |
| `switchyard_http_args` | Alter request arguments. |
| `switchyard_http_timeout` | Per-provider timeout. |
| `switchyard_form_fields`, `switchyard_form_tags` | Alter what a form submission sends. |
| `switchyard_woocommerce_order_tags`, `switchyard_edd_payment_tags` | Alter purchase tags. |
| `switchyard_enable_integration` | Disable an integration. |
| `switchyard_klaviyo_revision` | Pin a different Klaviyo API revision. |

## Actions

| Action | Fires |
|---|---|
| `switchyard_loaded` | Plugin booted. |
| `switchyard_register_features` | Companion plugins register capabilities. |
| `switchyard_register_integrations` | Add an integration to the registry. |
| `switchyard_contact_created` / `switchyard_contact_updated` | After a write. |
| `switchyard_tags_updated` | The cached tag set changed. |
| `switchyard_order_synced` | An order was synced. Receives a normalised `Order`. |
| `switchyard_order_refunded` | An order was refunded. |
| `switchyard_form_submitted` | A form was processed. |
| `switchyard_webhook_received` | A CRM webhook was handled. |
| `switchyard_access_denied` | Someone was refused a post. |
| `switchyard_contact_imported` | A contact became a user. |

## Order objects

`switchyard_order_synced` receives a `Switchyard\Crm\Order`, which is store-agnostic:
WooCommerce and Easy Digital Downloads produce the same shape, so a listener
only learns one.

```php
add_action( 'switchyard_order_synced', function ( Switchyard\Crm\Order $order, int $user_id ): void {
    foreach ( $order->lines as $line ) {
        // $line->product_id, $line->name, $line->quantity, $line->price
    }
}, 10, 2 );
```

## Calling a provider directly

Providers expose the full CRM surface, including endpoints the free plugin does
not wire a feature to. Check the capability first.

```php
$provider = switchyard_get_provider();

if ( $provider instanceof Switchyard\Crm\Contracts\SupportsEvents ) {
    $provider->track_event( $contact_id, 'Course Completed', array( 'course' => 'Intro' ) );
}
```

Capability interfaces: `SupportsTags`, `SupportsLists`, `SupportsTracking`,
`SupportsEvents`, `SupportsWebhooks`, `SupportsContactImport`.

Provider methods throw `Switchyard\Crm\Exception\CrmException` subclasses. Catch
them; do not let one reach a checkout hook.

## WP-CLI

```bash
wp switchyard status
wp switchyard sync <user>
wp switchyard sync
wp switchyard tags <user> [--refresh]
wp switchyard import [--tag=<id>] [--role=<role>]
```
