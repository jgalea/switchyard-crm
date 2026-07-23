# Adding a CRM provider

Adding a CRM is one class and one test. The contract suite does the rest.

## 1. The adapter

Extend `AbstractProvider` and implement `Provider`, plus whichever capability
interfaces the CRM genuinely supports. Do not implement a capability the CRM
fakes badly: the engine asks before dispatching, and a half-working tag
implementation is worse than none.

```php
final class Example extends AbstractProvider implements SupportsTags {

    public function get_slug(): string { return 'example'; }
    public function get_name(): string { return 'Example CRM'; }

    public function get_credential_fields(): array {
        return array(
            'api_key' => array(
                'label'    => __( 'API Key', 'conduit-crm' ),
                'type'     => 'password',
                'required' => true,
            ),
        );
    }

    protected function base_url(): string {
        return 'https://api.example.com/v1';
    }

    protected function auth_headers(): array {
        return array( 'Authorization' => 'Bearer ' . $this->credential( 'api_key' ) );
    }

    protected function verify_credentials(): void {
        try {
            $this->http()->get( '/me' );
        } catch ( CrmException $e ) {
            throw new ConnectionException( __( 'Example rejected this key.', 'conduit-crm' ) );
        }
    }

    // …the Provider methods…
}
```

Rules that the contract suite enforces:

- Rejected credentials must raise `ConnectionException`. It is the only
  exception the settings screen and the registry catch.
- The slug must be URL-safe: it appears in the webhook route and option keys.
- Cache catalogue reads through `$this->cached()`. Tags and fields are read on
  nearly every admin page load.
- Field identifiers are opaque to the rest of the plugin. Prefix custom fields
  however you like and translate in your own `to_payload()`.

## 2. Register it

Add the fully qualified class to `ProviderRegistry::register_defaults()`, or from
another plugin:

```php
add_action( 'conduit_loaded', function ( $plugin ) {
    $plugin->get( Conduit\Crm\ProviderRegistry::class )->register( MyProvider::class );
} );
```

Use the fully qualified name. `Provider\Foo::class` inside the registry resolves
against the imported `Provider` interface, not the provider namespace.

## 3. The test

```php
final class ExampleTest extends ProviderContractTestCase {

    protected function fresh_provider(): Provider {
        return new Example( $this->client() );
    }

    protected function provider(): Provider {
        $p = $this->fresh_provider();
        $p->connect( $this->credentials() );
        return $p;
    }

    protected function credentials(): array { return array( 'api_key' => 'test' ); }
    protected function expected_contact_id(): string { return '1'; }
    protected function sample_fields(): array { return array( 'email' => 'a@example.com' ); }

    protected function fixtures(): array {
        // Matched as a substring against "METHOD url". First hit wins, so put
        // specific patterns before general ones.
        return array(
            'GET https://api.example.com/v1/me'       => array( 'body' => '{}' ),
            'POST https://api.example.com/v1/contacts' => array( 'body' => '{"id":"1"}' ),
        );
    }
}
```

That inherits sixteen assertions covering identity, credential shape, contact
CRUD, capability coherence and failure behaviour. Add provider-specific tests
for anything unusual: an odd auth scheme, a required header, a field that has to
be nested, a delete that needs a join-record ID rather than the obvious one.
Those are where the real bugs live.

## 4. Update the counts

`ProviderRegistryTest` asserts the shipped provider count, so the README and the
test list change together on purpose.
