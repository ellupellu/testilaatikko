<?php

namespace MediaWiki\User\TempUser;

/**
 * A mapping which converts sequential input into an output sequence that looks
 * pseudo-random, but preserves the base-10 length of the input number.
 *
 * Take a sequence generated by multiplying the previous element of the
 * sequence by a fixed number "g", then applying the modulus "p":
 *
 *   X(0) = 1
 *   X(i) = ( g X(i-1) ) mod p
 *
 * If g is a primitive root modulo p, then this sequence will cover all values
 * from 1 to p-1 before it repeats. X(i) is a modular exponential function
 * (g^i mod p) and algorithms are available to calculate it efficiently.
 *
 * Loosely speaking, we choose a sequence based on the number of digits N in the
 * input, with the period being approximately 10^N, so that the number of digits
 * in the output will be approximately the same.
 *
 * More precisely, after offsetting the subsequent sequences to avoid colliding
 * with the previous sequences, the period ends up being about 0.9 * 10^N
 *
 * The modulo p is always a prime number because that makes the maths easier.
 * We use a value for g close to p/sqrt(3) since that seems to stir the digits
 * better than the largest or smallest primitive root.
 *
 * @internal
 */
class ScrambleMapping implements SerialMapping {
	/**
	 * Appropriately sized prime moduli and their associated largest primitive
	 * root. Generated with this GP/PARI script:
	 * s=0; \
	 * for(q = 2, 10, \
	 *   p=precprime(10^q - s); \
	 *   s = s + p; \
	 *   forstep(i = floor(p/sqrt(3)), 1, -1, \
	 *     if(znorder(Mod(i, p)) == p-1, \
	 *     print("[ ", i, ", ", p, " ],"); \
	 *     break )))
	 */
	private const GENERATORS = [
		[ 56, 97 ],
		[ 511, 887 ],
		[ 5203, 9013 ],
		[ 51947, 90001 ],
		[ 519612, 900001 ],
		[ 5196144, 8999993 ],
		[ 51961523, 89999999 ],
		[ 519615218, 899999963 ],
		[ 5196152444, 9000000043 ],
	];

	/** @var int */
	private $offset;

	/** @var bool */
	private $hasGmp;
	/** @var bool */
	private $hasBcm;

	public function __construct( $config ) {
		$this->offset = $config['offset'] ?? 0;
		$this->hasGmp = extension_loaded( 'gmp' );
		$this->hasBcm = extension_loaded( 'bcmath' );
		if ( !$this->hasGmp && !$this->hasBcm ) {
			throw new \MWException( __CLASS__ . ' requires the bcmath or gmp extension' );
		}
	}

	public function getSerialIdForIndex( int $index ): string {
		if ( $index <= 0 ) {
			return (string)$index;
		}
		$offset = $this->offset;
		foreach ( self::GENERATORS as [ $g, $p ] ) {
			if ( $index - $offset < $p ) {
				return (string)( $offset + $this->powmod( $g, $index - $offset, $p ) );
			}
			$offset += $p - 1;
		}
		throw new \MWException( __METHOD__ . ": The index $index is too large" );
	}

	private function powmod( $num, $exponent, $modulus ) {
		if ( $this->hasGmp ) {
			return \gmp_intval( \gmp_powm( $num, $exponent, $modulus ) );
		} elseif ( $this->hasBcm ) {
			return (int)\bcpowmod( (string)$num, (string)$exponent, (string)$modulus );
		} else {
			throw new \MWException( __CLASS__ . ' requires the bcmath or gmp extension' );
		}
	}
}
