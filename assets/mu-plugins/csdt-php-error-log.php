<?php
// Redirects PHP error_log to a readable path — managed by CloudScale DevTools.
// phpcs:ignore WordPress.PHP.IniSet.Risky
$_csdt_log = (string) get_option( 'csdt_php_error_log_path', '' );
if ( $_csdt_log ) {
	@ini_set( 'error_log', $_csdt_log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
unset( $_csdt_log );
