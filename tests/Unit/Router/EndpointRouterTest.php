<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Router;

use Brain\Monkey\Functions;
use Markout\Http\MarkdownRequestHandlerInterface;
use Markout\Router\EndpointRouter;
use Markout\Tests\TestCase;

final class EndpointRouterTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	public function test_is_markdown_request_true_when_md_key_present(): void {
		$router = new EndpointRouter( $this->fakeHandler() );

		self::assertTrue( $router->isMarkdownRequest( array( 'md' => '' ) ) );
	}

	public function test_is_markdown_request_false_when_md_key_absent(): void {
		$router = new EndpointRouter( $this->fakeHandler() );

		self::assertFalse( $router->isMarkdownRequest( array( 'page' => '2' ) ) );
	}

	public function test_maybe_respond_invokes_handler_for_matching_singular_post(): void {
		$post     = new \WP_Post();
		$post->ID = 7;

		$handler = $this->fakeHandler();
		$router  = new EndpointRouter( $handler );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'md' => '' ) );

		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $post );

		$router->maybeRespond();

		self::assertSame( $post, $handler->handledPost );
	}

	public function test_maybe_respond_does_nothing_when_not_a_markdown_request(): void {
		$handler = $this->fakeHandler();
		$router  = new EndpointRouter( $handler );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array() );

		$router->maybeRespond();

		self::assertNull( $handler->handledPost );
	}

	public function test_maybe_respond_does_nothing_when_not_singular(): void {
		$handler = $this->fakeHandler();
		$router  = new EndpointRouter( $handler );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'md' => '' ) );

		Functions\when( 'is_singular' )->justReturn( false );

		$router->maybeRespond();

		self::assertNull( $handler->handledPost );
	}

	public function test_maybe_respond_does_nothing_when_queried_object_is_not_a_post(): void {
		$handler = $this->fakeHandler();
		$router  = new EndpointRouter( $handler );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'md' => '' ) );

		Functions\when( 'is_singular' )->justReturn( true );
		// e.g. a term/user archive object, or null — anything that is not WP_Post.
		Functions\when( 'get_queried_object' )->justReturn( null );

		$router->maybeRespond();

		self::assertNull( $handler->handledPost );
	}

	public function test_register_hooks_into_init_and_template_redirect(): void {
		Functions\expect( 'add_action' )->twice();

		$router = new EndpointRouter( $this->fakeHandler() );

		$router->register();
	}

	public function test_register_init_callback_declares_rewrite_endpoint(): void {
		if ( ! defined( 'EP_PERMALINK' ) ) {
			define( 'EP_PERMALINK', 1024 );
		}

		$initCallback = null;
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$initCallback ): void {
				if ( 'init' === $hook ) {
					$initCallback = $callback;
				}
			}
		);

		$router = new EndpointRouter( $this->fakeHandler() );
		$router->register();

		self::assertIsCallable( $initCallback );

		// Invoke the captured init callback and assert it registers the endpoint.
		$registered = null;
		Functions\when( 'add_rewrite_endpoint' )->alias(
			static function ( string $name, int $places ) use ( &$registered ): void {
				$registered = array( $name, $places );
			}
		);

		$initCallback();

		self::assertSame( array( 'md', EP_PERMALINK ), $registered );
	}

	/**
	 * @return MarkdownRequestHandlerInterface&object{handledPost: ?\WP_Post}
	 */
	private function fakeHandler(): MarkdownRequestHandlerInterface {
		return new class() implements MarkdownRequestHandlerInterface {
			public ?\WP_Post $handledPost = null;

			public function handle( \WP_Post $post ): void {
				$this->handledPost = $post;
			}
		};
	}
}
