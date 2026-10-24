<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Access\Rules;
use Switchyard\Admin\PostMetaBox;
use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Contracts\SupportsTags;
use Switchyard\Crm\Exception\ApiException;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\TagEngine;
use Switchyard\Tests\TestCase;
use WP_Post;

final class PostMetaBoxTest extends TestCase {

	/** @var array<string, mixed> */
	private array $meta = array();

	/** @var array<string, mixed> */
	private array $written = array();

	/** @var list<string> */
	private array $deleted = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->alias( fn ( int $id, string $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->written[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): bool {
				$this->deleted[] = $key;

				return true;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name ): void {
				echo '<input type="hidden" name="' . $name . '">';
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		$_POST = array();

		parent::tearDown();
	}

	private function box( bool $fails ): PostMetaBox {
		$provider = new class( $fails ) implements Provider, SupportsTags {
			private bool $fails;

			public function __construct( bool $fails ) {
				$this->fails = $fails; }
			public function get_slug(): string {
				return 'tagcrm'; }
			public function get_name(): string {
				return 'Tag CRM'; }
			public function get_credential_fields(): array {
				return array(); }
			public function connect( array $credentials ): void {}
			public function get_contact_id( string $email ): ?string {
				return '1'; }
			public function add_contact( array $fields ): string {
				return '1'; }
			public function update_contact( string $contact_id, array $fields ): void {}
			public function load_contact( string $contact_id ): array {
				return array(); }
			public function get_available_fields(): array {
				return array(); }
			public function get_available_tags(): array {
				if ( $this->fails ) {
					throw new ApiException( 'down' );
				}

				return array( '10' => 'Customer' );
			}
			public function get_tags( string $contact_id ): array {
				return array(); }
			public function apply_tags( string $contact_id, array $tag_ids ): void {}
			public function remove_tags( string $contact_id, array $tag_ids ): void {}
			public function create_tag( string $label ): string {
				return '1'; }
		};

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );

		$active = new \ReflectionProperty( $registry, 'active' );
		$active->setValue( $registry, $provider );

		$tags = new TagEngine( $registry, new ContactResolver( $registry, $logger ), $settings, $logger );

		return new PostMetaBox( new Rules( $tags ), $tags );
	}

	private function post(): WP_Post {
		$post     = new WP_Post();
		$post->ID = 5;

		return $post;
	}

	private function render( bool $fails, string $which ): string {
		ob_start();

		if ( 'purchase' === $which ) {
			$this->box( $fails )->render_purchase_tags( $this->post() );
		} else {
			$this->box( $fails )->render( $this->post() );
		}

		return (string) ob_get_clean();
	}

	public function test_stored_tag_missing_from_the_crm_list_still_renders_selected_value(): void {
		$this->meta[ PostMetaBox::META_PURCHASE_TAGS ] = array( '99' );

		$html = $this->render( false, 'purchase' );

		$this->assertStringContainsString( 'value="99"', $html );
		$this->assertStringContainsString( 'value="10"', $html );
	}

	public function test_purchase_box_outputs_no_field_when_the_crm_fails(): void {
		$html = $this->render( true, 'purchase' );

		$this->assertStringNotContainsString( 'switchyard_purchase_tags_nonce', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_access_box_outputs_no_field_when_the_crm_fails(): void {
		$html = $this->render( true, 'access' );

		$this->assertStringNotContainsString( 'switchyard_access_nonce', $html );
		$this->assertStringNotContainsString( 'switchyard_restrict', $html );
	}

	public function test_save_without_the_form_leaves_stored_tags_alone(): void {
		$_POST = array();

		$this->box( true )->save_purchase_tags( 5, $this->post() );
		$this->box( true )->save( 5, $this->post() );

		$this->assertSame( array(), $this->deleted );
		$this->assertSame( array(), $this->written );
	}

	public function test_access_save_without_a_tag_field_keeps_stored_tags(): void {
		$_POST = array(
			'switchyard_access_nonce' => 'x',
			'switchyard_restrict'     => '1',
		);

		$this->box( false )->save( 5, $this->post() );

		$this->assertArrayHasKey( Rules::META_ENABLED, $this->written );
		$this->assertArrayNotHasKey( Rules::META_TAGS, $this->written );
		$this->assertSame( array(), $this->deleted );
	}
}
