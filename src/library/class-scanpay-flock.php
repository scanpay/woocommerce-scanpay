<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Simple file-based lock using flock(). Works across PHP-FPM workers on the same host
 * and filesystem, and is released automatically when the handle is closed or the
 * process exits. The lock file lives in get_temp_dir(), so WP_TEMP_DIR relocates it.
 */
final class Scanpay_Flock {
	private string $path;
	private $handle = null; // Untyped: PHP has no type declaration for a stream resource.

	public function __construct( int $shopid ) {
		$this->path = rtrim( get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . "scanpay_{$shopid}.lock";
	}

	public function __destruct() {
		$this->release();
	}

	/**
	 * Attempt to acquire the lock (non-blocking). The handle stays local until locked, so
	 * $this->handle is always either a locked stream or null; a repeat call fails as busy.
	 *
	 * Contention and setup failure are deliberately distinct: false means another process
	 * holds the lock (retry later), while the exception means the lock file could not even
	 * be opened -- nobody is draining, so the caller must surface that rather than "busy".
	 *
	 * @throws RuntimeException If the lock file cannot be opened.
	 */
	public function acquire(): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$handle = @fopen( $this->path, 'c' );
		if ( ! $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new RuntimeException( "could not open lock file: {$this->path}" );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // Busy: close now, or the fd leaks for the request.
			scanpay_log( 'debug', "lock busy: {$this->path}" );
			return false;
		}
		$this->handle = $handle;
		return true;
	}

	/** Release the lock. Idempotent, so the destructor can call it unconditionally. */
	public function release(): void {
		if ( null !== $this->handle ) {
			flock( $this->handle, LOCK_UN );
			fclose( $this->handle );
			$this->handle = null;
		}
	}
}
