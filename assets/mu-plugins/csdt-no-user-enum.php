<?php
// Block author enumeration — installed by CloudScale DevTools Quick Fixes.
add_action(
	'template_redirect',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no data mutation
		if ( ! is_admin() && isset( $_GET['author'] ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
);
