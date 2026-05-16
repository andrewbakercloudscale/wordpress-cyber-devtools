<?php
// Belt-and-suspenders: redirect error_log to safe path — managed by CloudScale DevTools.
// phpcs:ignore WordPress.PHP.IniSet.Risky
$_csdt_log = (string) get_option( 'csdt_debug_log_path', '' );
if ( $_csdt_log ) {
	@ini_set( 'error_log', $_csdt_log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
unset( $_csdt_log );
