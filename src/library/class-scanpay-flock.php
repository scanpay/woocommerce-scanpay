<?php

declare(strict_types=1);

/**
 * Simple file-based lock using flock().
 * Works across PHP-FPM workers on the same host and filesystem.
 * Automatically released when the handle is closed or the process exits.
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
	 *
	 * @return bool True if lock acquired, false if busy or failed.
	 */
	public function acquire(): bool {
		$this->handle = @fopen( $this->path, 'c' );
		if ( ! $this->handle ) {
			scanpay_log( 'error', "could not open lock file: {$this->path}" );
			return false;
		}
		if ( ! @flock( $this->handle, LOCK_EX | LOCK_NB ) ) {
			// Busy: release handle to avoid FD leak
			@fclose( $this->handle );
			$this->handle = null;
			scanpay_log( 'debug', "lock busy: {$this->path}" );
			return false;
		}
		scanpay_log( 'debug', "lock acquired: {$this->path}" );
		return true;
	}

	/**
	 * Release the lock.
	 */
	public function release(): void {
		if ( null !== $this->handle ) {
			@flock( $this->handle, LOCK_UN );
			@fclose( $this->handle );
			$this->handle = null;
		}
	}
}
