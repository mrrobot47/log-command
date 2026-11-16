<?php

use EE\Tests\ShellProbe;
use EE\Tests\TestCase;

/**
 * `ee log show`: which log files are followed per site type, flag and scope, and the `tail` invocation (src/Log_Command.php).
 *
 * `tail` is a stub on a PATH with nothing else on it, so the real sink (system() on develop) runs without following anything,
 * and the stub records the argv that tail would have received.
 */
class LogCommandTest extends TestCase {

	private $env_backup = [];
	private $site_dirs  = [];
	private $tail_log;

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'PATH', 'EE_FAKE_TAIL_LOG', 'EE_FAKE_TAIL_EXIT' ] as $var ) {
			$this->env_backup[ $var ] = getenv( $var );
		}
		$bin = $this->sandbox();
		file_put_contents( "$bin/tail", "#!/bin/sh\nprintf '%s\\0' \"\$@\" > \"\$EE_FAKE_TAIL_LOG\"\nexit \"\${EE_FAKE_TAIL_EXIT:-0}\"\n" );
		chmod( "$bin/tail", 0755 );
		$this->tail_log = $this->sandbox() . '/tail-argv';
		putenv( "PATH=$bin" );
		putenv( "EE_FAKE_TAIL_LOG={$this->tail_log}" );
		putenv( 'EE_FAKE_TAIL_EXIT' );
		// The shell runs in the cwd; injection markers land here.
		chdir( $this->sandbox() );
	}

	protected function tearDown(): void {
		foreach ( $this->env_backup as $var => $value ) {
			putenv( false === $value ? $var : "$var=$value" );
		}
		foreach ( array_merge( $this->site_dirs, [ EE_ROOT_DIR . '/logs/ee.log' ] ) as $path ) {
			exec( 'rm -rf ' . escapeshellarg( $path ) );
		}
		$this->site_dirs = [];
		parent::tearDown();
	}

	/**
	 * Insert a site under WEBROOT and create its log files.
	 *
	 * @param string[] $files Paths relative to the site dir.
	 *
	 * @return string Site dir.
	 */
	private function site( $url, $type, array $files, array $fields = [] ) {
		$dir = WEBROOT . $url;
		mkdir( $dir, 0755, true );
		$this->site_dirs[] = $dir;
		$this->insert_site( $fields + [ 'site_url' => $url, 'site_type' => $type, 'site_fs_path' => $dir ] );
		foreach ( $files as $file ) {
			$this->touch( "$dir/$file" );
		}

		return $dir;
	}

	private function touch( $path ) {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		touch( $path );
	}

	private function all_site_logs() {
		return [ 'logs/nginx/access.log', 'logs/nginx/error.log', 'logs/php/access.log', 'logs/php/error.log', 'app/htdocs/wp-content/debug.log' ];
	}

	private function show( array $args, array $assoc_args = [] ) {
		( new Log_Command() )->show( $args, $assoc_args );
	}

	/** @return array|null [ 'lines' => the -n value, 'files' => sorted file arguments ], or null when tail didn't run. */
	private function tail() {
		if ( ! file_exists( $this->tail_log ) ) {
			return null;
		}
		$argv = explode( "\0", rtrim( file_get_contents( $this->tail_log ), "\0" ) );
		$this->assertSame( [ '-n' ], array_slice( $argv, 0, 1 ), 'tail argv: ' . json_encode( $argv ) );
		$this->assertSame( '-f', $argv[2], 'tail argv: ' . json_encode( $argv ) );
		$files = array_slice( $argv, 3 );
		if ( isset( $files[0] ) && '--' === $files[0] ) {
			array_shift( $files );
		}
		sort( $files );

		return [ 'lines' => $argv[1], 'files' => $files ];
	}

	/** @return string[] Sorted absolute paths of $files in $dir. */
	private function paths( $dir, array $files ) {
		$paths = array_map(
			function ( $file ) use ( $dir ) {
				return "$dir/$file";
			},
			$files
		);
		sort( $paths );

		return $paths;
	}

	private function assert_follows( array $expected_files, $lines = '10' ) {
		$tail = $this->tail();
		$this->assertNotNull( $tail, 'tail did not run. STDERR: ' . $this->stderr() );
		$this->assertSame( (string) $lines, $tail['lines'] );
		$this->assertSame( $expected_files, $tail['files'] );
	}

	/**
	 * @dataProvider site_flag_provider
	 */
	public function test_wp_site_logs_per_flag( array $flags, array $expected, $lines = '10' ) {
		$dir = $this->site( 'example.test', 'wp', array_merge( $this->all_site_logs(), [ 'logs/nginx/access.log.1', 'logs/php/slow.txt' ] ) );

		$this->show( [ 'example.test' ], $flags );

		$this->assert_follows( $this->paths( $dir, $expected ), $lines );
	}

	public function site_flag_provider() {
		$nginx = [ 'logs/nginx/access.log', 'logs/nginx/error.log' ];
		$php   = [ 'logs/php/access.log', 'logs/php/error.log' ];
		$wp    = [ 'app/htdocs/wp-content/debug.log' ];

		return [
			'default: nginx, php, wp' => [ [], array_merge( $nginx, $php, $wp ) ],
			'--all'                   => [ [ 'all' => true ], array_merge( $nginx, $php, $wp ) ],
			'--nginx'                 => [ [ 'nginx' => true ], $nginx ],
			'--php'                   => [ [ 'php' => true ], $php ],
			'--wp'                    => [ [ 'wp' => true ], $wp ],
			'--nginx --wp'            => [ [ 'nginx' => true, 'wp' => true ], array_merge( $nginx, $wp ) ],
			'--access'                => [ [ 'access' => true ], [ 'logs/nginx/access.log', 'logs/php/access.log' ] ],
			'--error'                 => [ [ 'error' => true ], [ 'logs/nginx/error.log', 'logs/php/error.log' ] ],
			'--n alone keeps default' => [ [ 'n' => '5' ], array_merge( $nginx, $php, $wp ), '5' ],
		];
	}

	public function test_line_count_is_passed_to_tail_and_paths_are_shown_relative_to_the_sites_dir() {
		$this->site( 'example.test', 'wp', [ 'logs/nginx/error.log' ] );

		$this->show( [ 'example.test' ], [ 'n' => '50', 'nginx' => true ] );

		$this->assert_follows( [ WEBROOT . 'example.test/logs/nginx/error.log' ], '50' );
		$this->assertSame( "watching logfile example.test/logs/nginx/error.log\n", $this->stdout() );
	}

	public function test_log_dirs_are_scanned_recursively() {
		$dir = $this->site( 'example.test', 'wp', [ 'logs/nginx/error.log', 'logs/nginx/vhost/custom.log' ] );

		$this->show( [ 'example.test' ], [ 'nginx' => true ] );

		$this->assert_follows( $this->paths( $dir, [ 'logs/nginx/error.log', 'logs/nginx/vhost/custom.log' ] ) );
	}

	public function test_html_site_access_logs_skip_the_php_dir() {
		$dir = $this->site( 'example.test', 'html', [ 'logs/nginx/access.log', 'logs/nginx/error.log', 'logs/php/access.log' ] );

		$this->show( [ 'example.test' ], [ 'access' => true ] );

		$this->assert_follows( $this->paths( $dir, [ 'logs/nginx/access.log' ] ) );
	}

	public function test_a_missing_wp_debug_log_is_skipped_with_a_warning() {
		$dir = $this->site( 'example.test', 'wp', [ 'logs/nginx/error.log', 'app/htdocs/wp-content/index.php' ] );

		$this->show( [ 'example.test' ], [] );

		$this->assert_follows( $this->paths( $dir, [ 'logs/nginx/error.log' ] ) );
		$this->assertStringContainsString( "wp log doesn't exist!", $this->stderr() );
	}

	public function test_no_log_files_is_an_error_and_tail_does_not_run() {
		$this->site( 'example.test', 'wp', [ 'logs/nginx/notes.txt' ] );

		$this->assert_ee_error(
			function () {
				$this->show( [ 'example.test' ], [] );
			},
			'No logs found!'
		);
		$this->assertNull( $this->tail() );
	}

	public function test_cli_follows_the_easyengine_log() {
		$this->site( 'example.test', 'wp', $this->all_site_logs() );
		$this->touch( EE_ROOT_DIR . '/logs/ee.log' );

		$this->show( [], [ 'cli' => true ] );

		$this->assert_follows( [ EE_ROOT_DIR . '/logs/ee.log' ] );
	}

	public function test_global_follows_the_cli_log_and_every_wp_site() {
		$a = $this->site( 'a.test', 'wp', $this->all_site_logs() );
		$b = $this->site( 'b.test', 'wp', [ 'logs/nginx/error.log' ] );
		$this->touch( EE_ROOT_DIR . '/logs/ee.log' );

		$this->show( [], [ 'global' => true ] );

		$expected = array_merge( [ EE_ROOT_DIR . '/logs/ee.log' ], $this->paths( $a, $this->all_site_logs() ), [ "$b/logs/nginx/error.log" ] );
		sort( $expected );
		$this->assert_follows( $expected );
	}

	public function test_an_unknown_site_is_an_error() {
		$this->site( 'example.test', 'wp', $this->all_site_logs() );
		$this->runner->on( '/^pwd$/', "/root\n" );

		$this->assert_ee_error(
			function () {
				$this->show( [ 'nope.test' ], [] );
			},
			'Could not find the site you wish to run log show command on.'
		);
		$this->assertNull( $this->tail() );
	}

	public function test_a_disabled_site_is_refused() {
		$this->site( 'example.test', 'wp', $this->all_site_logs(), [ 'site_enabled' => 0 ] );

		$this->assert_ee_error(
			function () {
				$this->show( [ 'example.test' ], [] );
			},
			'Site example.test is not enabled.'
		);
	}

	/**
	 * LOGCMD-1: --n is interpolated into `tail -n $lines -f ...` and run through system().
	 *
	 * @group known-bug
	 * @group LOGCMD-1
	 */
	public function test_line_count_is_shell_safe() {
		$this->site( 'example.test', 'wp', [ 'logs/nginx/error.log' ] );
		$failures = [];
		foreach ( ShellProbe::payloads( 'eeprobemarker' ) as $name => $payload ) {
			try {
				$this->show( [ 'example.test' ], [ 'n' => $payload, 'nginx' => true ] );
			} catch ( EE\ExitException $e ) {
				// Rejecting the value is fine.
			}
			if ( file_exists( 'eeprobemarker' ) ) {
				$failures[] = $name;
				unlink( 'eeprobemarker' );
			}
		}
		$this->assertSame( [], $failures, 'Payloads in --n reached the shell.' );
	}

	/**
	 * LOGCMD-1: log file names come from dirs the site containers can write to and reach the shell unquoted.
	 *
	 * @group known-bug
	 * @group LOGCMD-1
	 */
	public function test_log_file_names_are_shell_safe() {
		$dir      = $this->site( 'example.test', 'wp', [] );
		$failures = [];
		foreach ( ShellProbe::payloads( 'eeprobemarker' ) as $name => $payload ) {
			exec( 'rm -rf ' . escapeshellarg( "$dir/logs" ) );
			$this->touch( "$dir/logs/nginx/x{$payload}.log" );
			try {
				$this->show( [ 'example.test' ], [ 'nginx' => true ] );
			} catch ( EE\ExitException $e ) {
				// Refusing the file is fine.
			}
			if ( file_exists( 'eeprobemarker' ) ) {
				$failures[] = $name;
				unlink( 'eeprobemarker' );
			}
		}
		$this->assertSame( [], $failures, 'Payloads in log file names reached the shell.' );
	}

	/**
	 * LOGCMD-1: a log file name with spaces is split into several tail arguments.
	 *
	 * @group known-bug
	 * @group LOGCMD-1
	 */
	public function test_a_log_file_name_with_spaces_is_one_tail_argument() {
		$dir = $this->site( 'example.test', 'wp', [ 'logs/nginx/my site.log' ] );

		$this->show( [ 'example.test' ], [ 'nginx' => true ] );

		$this->assert_follows( [ "$dir/logs/nginx/my site.log" ] );
	}

	/**
	 * LOGCMD-2: --n isn't validated, so `--n=abc` reaches tail and ee exits 0.
	 *
	 * @group known-bug
	 * @group LOGCMD-2
	 */
	public function test_a_non_numeric_line_count_is_rejected_before_tail_runs() {
		$this->site( 'example.test', 'wp', [ 'logs/nginx/error.log' ] );

		try {
			$this->show( [ 'example.test' ], [ 'n' => 'abc' ] );
		} catch ( EE\ExitException $e ) {
			$this->assertNull( $this->tail(), 'tail ran although --n was rejected.' );

			return;
		}
		$this->fail( '--n=abc was accepted; tail got: ' . json_encode( $this->tail() ) );
	}

	/**
	 * LOGCMD-2: system()'s exit status is ignored, so a failing tail still ends in exit 0.
	 *
	 * @group known-bug
	 * @group LOGCMD-2
	 */
	public function test_a_failing_tail_is_an_error() {
		$this->site( 'example.test', 'wp', [ 'logs/nginx/error.log' ] );
		putenv( 'EE_FAKE_TAIL_EXIT=1' );

		$this->assert_ee_error(
			function () {
				$this->show( [ 'example.test' ], [ 'nginx' => true ] );
			}
		);
	}

	/**
	 * LOGCMD-3: `unset( $final_types['php'], $final_types['wp'] )` uses string keys on a list, so html sites still follow php and wp logs.
	 *
	 * @group known-bug
	 * @group LOGCMD-3
	 */
	public function test_html_site_follows_only_nginx_logs() {
		$dir = $this->site( 'example.test', 'html', $this->all_site_logs() );

		$this->show( [ 'example.test' ], [] );

		$this->assert_follows( $this->paths( $dir, [ 'logs/nginx/access.log', 'logs/nginx/error.log' ] ) );
	}

	/**
	 * LOGCMD-3: `unset( $final_types['wp'] )` is a no-op, so php sites follow a wp-content/debug.log.
	 *
	 * @group known-bug
	 * @group LOGCMD-3
	 */
	public function test_php_site_does_not_follow_a_wp_debug_log() {
		$dir = $this->site( 'example.test', 'php', $this->all_site_logs() );

		$this->show( [ 'example.test' ], [] );

		$this->assert_follows( $this->paths( $dir, [ 'logs/nginx/access.log', 'logs/nginx/error.log', 'logs/php/access.log', 'logs/php/error.log' ] ) );
	}

	/**
	 * LOGCMD-4: `$wp_logs_path` is only assigned for wp sites, so a non-wp site before the first wp site reads an undefined variable.
	 *
	 * @group known-bug
	 * @group LOGCMD-4
	 */
	public function test_global_handles_a_non_wp_site_before_a_wp_site() {
		$a = $this->site( 'a.test', 'html', [ 'logs/nginx/error.log' ] );
		$b = $this->site( 'b.test', 'wp', [ 'logs/nginx/error.log', 'app/htdocs/wp-content/debug.log' ] );

		$this->show( [], [ 'global' => true ] );

		$this->assert_follows( [ "$a/logs/nginx/error.log", "$b/app/htdocs/wp-content/debug.log", "$b/logs/nginx/error.log" ] );
	}

	/**
	 * LOGCMD-5: --global loops over every log type and ignores --nginx/--php/--wp/--access/--error.
	 *
	 * @group known-bug
	 * @group LOGCMD-5
	 */
	public function test_global_honours_the_type_flags() {
		$this->touch( EE_ROOT_DIR . '/logs/ee.log' );
		$dir = $this->site( 'example.test', 'wp', $this->all_site_logs() );

		$this->show( [], [ 'global' => true, 'nginx' => true ] );

		$tail = $this->tail();
		$this->assertNotNull( $tail );
		$this->assertSame( [], array_values( array_intersect( $tail['files'], $this->paths( $dir, [ 'logs/php/access.log', 'logs/php/error.log', 'app/htdocs/wp-content/debug.log' ] ) ) ) );
	}

	/**
	 * LOGCMD-7: pathinfo()['extension'] is read without isset(), so a file without an extension in a log dir raises a warning/notice.
	 *
	 * @group known-bug
	 * @group LOGCMD-7
	 */
	public function test_a_file_without_extension_in_a_log_dir_is_ignored() {
		$dir = $this->site( 'example.test', 'wp', [ 'logs/nginx/error.log', 'logs/nginx/README' ] );

		$this->show( [ 'example.test' ], [ 'nginx' => true ] );

		$this->assert_follows( [ "$dir/logs/nginx/error.log" ] );
	}

	/**
	 * LOGCMD-8: auto_site_name() strips the scheme only for its own lookup, so get_site_info() looks up `http://example.test`.
	 *
	 * @group known-bug
	 * @group LOGCMD-8
	 */
	public function test_a_site_url_with_a_scheme_resolves_to_the_site() {
		$dir = $this->site( 'example.test', 'wp', [ 'logs/nginx/error.log' ] );

		try {
			$this->show( [ 'http://example.test' ], [ 'nginx' => true ] );
		} catch ( EE\ExitException $e ) {
			$this->fail( 'ee log show http://example.test exited: ' . $this->stderr() );
		}
		$this->assert_follows( [ "$dir/logs/nginx/error.log" ] );
	}

	/**
	 * LOGCMD-9: a symlinked *.log in a site log dir is followed, so root tail prints its target on the host.
	 *
	 * @group known-bug
	 * @group LOGCMD-9
	 */
	public function test_a_symlinked_log_is_not_followed() {
		$dir    = $this->site( 'example.test', 'wp', [ 'logs/nginx/error.log' ] );
		$secret = $this->sandbox() . '/host-secret';
		file_put_contents( $secret, "secret\n" );
		symlink( $secret, "$dir/logs/nginx/leak.log" );

		$this->show( [ 'example.test' ], [ 'nginx' => true ] );

		$this->assert_follows( [ "$dir/logs/nginx/error.log" ] );
	}
}
