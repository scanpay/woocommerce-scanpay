<?php

/**
 * Simple filesystem-based lock using a directory as the lock primitive.
 * Lock is automatically considered "stale" and can be re-acquired if not renewed for 240 seconds.
 * Intended for mutual exclusion (flock-like) across PHP processes on the same filesystem.
 */
class Scanpay_Flock {
	private bool $acquired = false;
	private string $path;

	/**
	 * Initialize the lock with a unique key (shopID)
	 * The lock path is built in the system temp directory for portability across platforms.
	 */
	public function __construct( string $key ) {
		$this->path = rtrim( get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'scanpay_' . $key . '_lock/';
	}

	/**
	 * Attempt to acquire the lock.
	 *
	 * @return bool True if lock acquired, false otherwise.
	 */
	public function acquire(): bool {
		if ( $this->acquired ) {
			return true;
		}
		if ( @mkdir( $this->path ) ) {
			$this->acquired = true;
			return true;
		}
		if ( file_exists( $this->path ) && ( time() - filemtime( $this->path ) ) > 240 ) {
			// stale lock
			$this->acquired = true;
			return true;
		}
		scanpay_log( 'error', 'could not acquire lock: ' . $this->path );
		return false;
	}

	/**
	 * Renew the lock by updating the directory's modification time.
	 *
	 * @throws Exception If lock renewal fails.
	 */
	public function renew(): void {
		if ( ! $this->acquired || ! @touch( $this->path ) ) {
			scanpay_log( 'error', 'could not renew lock: ' . $this->path );
			throw new Exception( 'could not renew lock: ' . $this->path );
		}
	}

	/**
	 * Release the lock by removing the lock directory.
	 * Has no effect if lock is not currently held.
	 */
	public function release(): void {
		if ( $this->acquired ) {
			@rmdir( $this->path );
			$this->acquired = false;
		}
	}
}
