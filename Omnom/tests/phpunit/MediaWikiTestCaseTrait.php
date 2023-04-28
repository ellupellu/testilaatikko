<?php

use MediaWiki\HookContainer\HookContainer;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Wikimedia\ObjectFactory\ObjectFactory;
use Wikimedia\Services\NoSuchServiceException;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * For code common to both MediaWikiUnitTestCase and MediaWikiIntegrationTestCase.
 */
trait MediaWikiTestCaseTrait {
	/** @var int|null */
	private $originalPhpErrorFilter;

	/** @var array */
	private $expectedDeprecations = [];

	/** @var array */
	private $actualDeprecations = [];

	/**
	 * Returns a PHPUnit constraint that matches (with `===`) anything other than a fixed set of values.
	 * This can be used to list accepted values, e.g.
	 *   $mock->expects( $this->never() )->method( $this->anythingBut( 'foo', 'bar' ) );
	 * which will throw if any unexpected method is called.
	 *
	 * @param mixed ...$values Values that are not matched
	 * @return Constraint
	 */
	protected function anythingBut( ...$values ) {
		if ( !in_array( '__destruct', $values, true ) ) {
			// Ensure that __destruct is always included. PHPUnit will fail very hard with no
			// useful output if __destruct ends up being called (T280780).
			$values[] = '__destruct';
		}
		return $this->logicalNot( $this->logicalOr(
			...array_map( [ $this, 'identicalTo' ], $values )
		) );
	}

	/**
	 * Return a PHPUnit mock that is expected to never have any methods called on it.
	 *
	 * @param string $type
	 * @param string[] $allow methods to allow
	 *
	 * @return MockObject
	 */
	protected function createNoOpMock( $type, $allow = [] ) {
		$mock = $this->createMock( $type );
		$mock->expects( $this->never() )->method( $this->anythingBut( '__destruct', ...$allow ) );
		return $mock;
	}

	/**
	 * Return a PHPUnit mock that is expected to never have any methods called on it.
	 *
	 * @param string $type
	 * @param string[] $allow methods to allow
	 * @return MockObject
	 */
	protected function createNoOpAbstractMock( $type, $allow = [] ) {
		$mock = $this->getMockBuilder( $type )
			->disableOriginalConstructor()
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->getMockForAbstractClass();
		$mock->expects( $this->never() )->method( $this->anythingBut( '__destruct', ...$allow ) );
		return $mock;
	}

	/**
	 * Create an ObjectFactory with no dependencies and no services
	 *
	 * @return ObjectFactory
	 */
	protected function createSimpleObjectFactory() {
		$serviceContainer = $this->createMock( ContainerInterface::class );
		$serviceContainer->method( 'has' )->willReturn( false );
		$serviceContainer->method( 'get' )->willReturnCallback(
			static function ( $serviceName ) {
				throw new NoSuchServiceException( $serviceName );
			}
		);
		return new ObjectFactory( $serviceContainer );
	}

	/**
	 * Create an initially empty HookContainer with an empty service container
	 * attached. Register only the hooks specified in the parameter.
	 *
	 * @param callable[] $hooks
	 * @return HookContainer
	 */
	protected function createHookContainer( $hooks = [] ) {
		$hookContainer = new HookContainer(
			new \MediaWiki\HookContainer\StaticHookRegistry(),
			$this->createSimpleObjectFactory()
		);
		foreach ( $hooks as $name => $callback ) {
			$hookContainer->register( $name, $callback );
		}
		return $hookContainer;
	}

	/**
	 * Check if $extName is a loaded PHP extension, will skip the
	 * test whenever it is not loaded.
	 *
	 * @since 1.21 added to MediaWikiIntegrationTestCase
	 * @since 1.37 moved to MediaWikiTestCaseTrait to be available in unit tests
	 * @param string $extName
	 * @return bool
	 */
	protected function checkPHPExtension( $extName ) {
		$loaded = extension_loaded( $extName );
		if ( !$loaded ) {
			$this->markTestSkipped( "PHP extension '$extName' is not loaded, skipping." );
		}

		return $loaded;
	}

	/**
	 * Don't throw a warning if $function is deprecated and called later
	 *
	 * @since 1.19
	 *
	 * @param string $function
	 */
	public function hideDeprecated( $function ) {
		// Construct a regex that will match the message generated by
		// wfDeprecated() if it is called for the specified function.
		$this->filterDeprecated( '/Use of ' . preg_quote( $function, '/' ) . ' /' );
	}

	/**
	 * Don't throw a warning for deprecation messages matching a regex.
	 *
	 * @since 1.35
	 *
	 * @param string $regex
	 */
	public function filterDeprecated( $regex ) {
		MWDebug::filterDeprecationForTest( $regex );
	}

	/**
	 * Expect a deprecation notice, but suppress it and continue operation so we can test that the
	 * deprecated functionality works as intended for compatibility.
	 *
	 * @since 1.39
	 *
	 * @param string $regex Deprecation message that must be triggered.
	 */
	public function expectDeprecationAndContinue( string $regex ): void {
		$this->expectedDeprecations[] = $regex;
		MWDebug::filterDeprecationForTest( $regex, function () use ( $regex ): void {
			$this->actualDeprecations[] = $regex;
		} );
	}

	/**
	 * @after
	 */
	public function checkExpectedDeprecationsOnTearDown(): void {
		if ( $this->expectedDeprecations ) {
			$this->assertSame( [],
				array_diff( $this->expectedDeprecations, $this->actualDeprecations ),
				'Expected deprecation warning(s) were not emitted' );
		}
	}

	/**
	 * Check whether file contains given data.
	 * @param string $fileName
	 * @param string $actualData
	 * @param bool $createIfMissing If true, and file does not exist, create it with given data
	 *                              and skip the test.
	 * @param string $msg
	 * @since 1.30
	 */
	protected function assertFileContains(
		$fileName,
		$actualData,
		$createIfMissing = false,
		$msg = ''
	) {
		if ( $createIfMissing ) {
			if ( !is_file( $fileName ) ) {
				file_put_contents( $fileName, $actualData );
				$this->markTestSkipped( "Data file $fileName does not exist" );
			}
		} else {
			$this->assertFileExists( $fileName );
		}
		$this->assertEquals( file_get_contents( $fileName ), $actualData, $msg );
	}

	/**
	 * Assert that two arrays are equal. By default this means that both arrays need to hold
	 * the same set of values. Using additional arguments, order and associated key can also
	 * be set as relevant.
	 *
	 * @since 1.20
	 *
	 * @param array $expected
	 * @param array $actual
	 * @param bool $ordered If the order of the values should match
	 * @param bool $named If the keys should match
	 * @param string $message
	 * @param float $delta Deprecated in assertEquals()
	 * @param int $maxDepth Deprecated in assertEquals()
	 * @param bool $canonicalize Deprecated in assertEquals()
	 * @param bool $ignoreCase Deprecated in assertEquals()
	 */
	public function assertArrayEquals(
		array $expected, array $actual, $ordered = false, $named = false, string $message = '',
		float $delta = 0.0, int $maxDepth = 10, bool $canonicalize = false, bool $ignoreCase = false
	) {
		if ( !$ordered ) {
			$this->objectAssociativeSort( $expected );
			$this->objectAssociativeSort( $actual );
		}

		if ( !$named ) {
			$expected = array_values( $expected );
			$actual = array_values( $actual );
		}

		$this->assertEquals(
			$expected, $actual, $message,
			// Deprecated args
			$delta, $maxDepth, $canonicalize, $ignoreCase
		);
	}

	/**
	 * Does an associative sort that works for objects.
	 *
	 * @since 1.20
	 *
	 * @param array &$array
	 */
	protected function objectAssociativeSort( array &$array ) {
		uasort(
			$array,
			static function ( $a, $b ) {
				return serialize( $a ) <=> serialize( $b );
			}
		);
	}

	/**
	 * @before
	 */
	protected function phpErrorFilterSetUp() {
		$this->originalPhpErrorFilter = error_reporting();
	}

	/**
	 * @after
	 */
	protected function phpErrorFilterTearDown() {
		$phpErrorFilter = error_reporting();

		if ( $phpErrorFilter !== $this->originalPhpErrorFilter ) {
			error_reporting( $this->originalPhpErrorFilter );
			$message = "PHP error_reporting setting found dirty."
				. " Did you forget AtEase::restoreWarnings?";
			$this->fail( $message );
		}
	}

	/**
	 * Re-enable any disabled deprecation warnings and allow same deprecations to be thrown
	 * multiple times in different tests, so the PHPUnit expectDeprecation() works.
	 *
	 * @after
	 */
	protected function mwDebugTearDown() {
		MWDebug::clearLog();
		MWDebug::clearDeprecationFilters();
	}

	/**
	 * Reset any fake timestamps so that they don't mess with any other tests.
	 *
	 * @since 1.37 before that, integration tests had it reset in
	 * MediaWikiIntegrationTestCase::mediaWikiTearDown, and unit tests didn't at all
	 *
	 * @after
	 */
	protected function fakeTimestampTearDown() {
		ConvertibleTimestamp::setFakeTime( null );
	}

	/**
	 * @param string $text
	 * @param array $params
	 * @return Message|MockObject
	 * @since 1.35
	 */
	protected function getMockMessage( $text = '', $params = [] ) {
		/** @var MockObject $msg */
		$msg = $this->createMock( Message::class );
		$msg->method( 'toString' )->willReturn( $text );
		$msg->method( '__toString' )->willReturn( $text );
		$msg->method( 'text' )->willReturn( $text );
		$msg->method( 'parse' )->willReturn( $text );
		$msg->method( 'plain' )->willReturn( $text );
		$msg->method( 'parseAsBlock' )->willReturn( $text );
		$msg->method( 'escaped' )->willReturn( $text );
		$msg->method( 'title' )->willReturn( $msg );
		$msg->method( 'getKey' )->willReturn( $text );
		$msg->method( 'params' )->willReturn( $msg );
		$msg->method( 'getParams' )->willReturn( $params );
		$msg->method( 'rawParams' )->willReturn( $msg );
		$msg->method( 'numParams' )->willReturn( $msg );
		$msg->method( 'inLanguage' )->willReturn( $msg );
		$msg->method( 'inContentLanguage' )->willReturn( $msg );
		$msg->method( 'useDatabase' )->willReturn( $msg );
		$msg->method( 'setContext' )->willReturn( $msg );
		$msg->method( 'exists' )->willReturn( true );
		return $msg;
	}

	private function failStatus( StatusValue $status, $reason, $message = '' ) {
		$reason = $message === '' ? $reason : "$message\n$reason";
		$this->fail( "$reason\n$status" );
	}

	protected function assertStatusOK( StatusValue $status, $message = '' ) {
		if ( !$status->isOK() ) {
			$errors = $status->splitByErrorType()[0];
			$this->failStatus( $errors, 'Status should be OK', $message );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	protected function assertStatusGood( StatusValue $status, $message = '' ) {
		if ( !$status->isGood() ) {
			$this->failStatus( $status, 'Status should be Good', $message );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	protected function assertStatusNotOK( StatusValue $status, $message = '' ) {
		if ( $status->isOK() ) {
			$this->failStatus( $status, 'Status should not be OK', $message );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	protected function assertStatusNotGood( StatusValue $status, $message = '' ) {
		if ( $status->isGood() ) {
			$this->failStatus( $status, 'Status should not be Good', $message );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	protected function assertStatusMessage( $messageKey, StatusValue $status, $message = '' ) {
		if ( !$status->hasMessage( $messageKey ) ) {
			$this->failStatus( $status, "Status should have message $messageKey", $message );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	protected function assertStatusValue( $expected, StatusValue $status, $message = 'Status value' ) {
		$this->assertEquals( $expected, $status->getValue(), $message );
	}

	protected function assertStatusError( $messageKey, StatusValue $status, $message = '' ) {
		$this->assertStatusNotOK( $status, $message );
		$this->assertStatusMessage( $messageKey, $status, $message );
	}

	protected function assertStatusWarning( $messageKey, StatusValue $status, $message = '' ) {
		$this->assertStatusNotGood( $status, $message );
		$this->assertStatusOK( $status, $message );
		$this->assertStatusMessage( $messageKey, $status, $message );
	}

	/**
	 * Put each HTML element on its own line and then equals() the results
	 *
	 * Use for nicely formatting of PHPUnit diff output when comparing very
	 * simple HTML
	 *
	 * @since 1.20
	 * @since 1.39 available in MediaWikiUnitTestCase
	 *
	 * @param string $expected HTML on oneline
	 * @param string $actual HTML on oneline
	 * @param string $msg Optional message
	 */
	protected function assertHTMLEquals( $expected, $actual, $msg = '' ) {
		$expected = str_replace( '>', ">\n", $expected );
		$actual = str_replace( '>', ">\n", $actual );

		$this->assertEquals( $expected, $actual, $msg );
	}
}
