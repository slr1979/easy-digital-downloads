<?php
/**
 * A Serializable stub whose unserialize() records that it ran.
 *
 * @package     EDD\Tests\Helpers\Stubs
 * @copyright   Copyright (c) 2026, Sandhills Development, LLC
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.7.1
 */

namespace EDD\Tests\Helpers\Stubs;

defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

/**
 * A Serializable class whose unserialize() records that it ran.
 *
 * @since 3.7.1
 */
class SerializableStub implements \Serializable {

	/**
	 * Whether unserialize() ran.
	 *
	 * @since 3.7.1
	 * @var bool
	 */
	public static $ran = false;

	/**
	 * Serializes the stub.
	 *
	 * @return string
	 */
	public function serialize() {
		return '';
	}

	/**
	 * Unserializes the stub.
	 *
	 * @param string $data The serialized data.
	 */
	public function unserialize( $data ) {
		self::$ran = true;
	}
}
