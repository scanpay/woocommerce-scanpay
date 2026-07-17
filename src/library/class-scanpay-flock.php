<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Simple file-based lock using flock().
 * Works across PHP-FPM workers on the same host and filesystem.
 * Automatically released when the handle is closed or the process exits.
 *
 * The lock lives in get_temp_dir() so merchants can relocate it via wp-config.
 */
final class Scanpay_Flock {
	private string $path;
	private $handle = null; // stream resource (PHP 8.3+)

	public function __construct( int $shopid ) {
		$this->path = rtrim( get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . "scanpay_{$shopid}.lock";
	}

	public function __destruct() {
		$this->release();
	}

	/**
	 * Attempt to acquire the lock (non-blocking).
	 * Keep the handle local until locked, so $this->handle is always a
	 * locked stream or null. Repeat acquire() calls fail as busy.
	 *
	 * Distinguishes contention from setup failure: a return of false means
	 * another process holds the lock (retry later), whereas a thrown exception
	 * means the lock file could not even be opened (no worker is running, so
	 * the caller must surface it rather than treat it as "busy").
	 *
	 * @return bool True if lock acquired, false if another process holds it.
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
			// Busy: release handle to avoid FD leak
			fclose( $handle );
			scanpay_log( 'debug', "lock busy: {$this->path}" );
			return false;
		}
		$this->handle = $handle;
		return true;
	}

	/**
	 * Release the lock.
	 */
	public function release(): void {
		if ( null !== $this->handle ) {
			flock( $this->handle, LOCK_UN );
			fclose( $this->handle );
			$this->handle = null;
		}
	}
}
