<?php
/**
 * .htaccess editor.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Technical_Tools;

use TagwrightSEO\Admin\Admin;
use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Admin\UI;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the .htaccess file in the site root, keeping the last
 * five versions so a bad edit can be undone from the same screen.
 *
 * Writing is refused when file editing is disabled for the site
 * (DISALLOW_FILE_EDIT), when the server is not Apache or LiteSpeed, and
 * when WordPress cannot write files directly (we never ask for FTP
 * credentials from a REST request). On multisite only super admins may
 * write, because the file is shared by the whole network.
 */
class Htaccess {

	/**
	 * Option with the backups.
	 */
	const BACKUP_OPTION = 'tagwright_seo_technical_tools_htaccess_backups';

	/**
	 * Versions kept.
	 */
	const BACKUPS = 5;

	/**
	 * Largest file we accept, in bytes.
	 */
	const MAX_SIZE = 65536;

	/**
	 * Marker WordPress writes around its own rules.
	 */
	const WP_MARKER = '# BEGIN WordPress';

	/**
	 * Registers the REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'tagwright-seo/v1',
			'/technical-tools/htaccess',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					// Raw on purpose: text sanitizers strip <IfModule> and friends. Checked in clean().
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
					'confirm' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			'tagwright-seo/v1',
			'/technical-tools/htaccess/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'time' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * Who may write the file.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}

		if ( is_multisite() && ! is_super_admin() ) {
			return false;
		}

		// The plugin capability can be filtered down to editors; writing
		// server config should always need an administrator as well.
		return current_user_can( 'manage_options' ) && current_user_can( Admin::capability() );
	}

	/**
	 * Full path of the file.
	 *
	 * @return string
	 */
	public static function path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return trailingslashit( get_home_path() ) . '.htaccess';
	}

	/**
	 * Whether the server reads .htaccess files at all.
	 *
	 * @return bool
	 */
	public static function is_apache() {
		global $is_apache;

		return ! empty( $is_apache );
	}

	/**
	 * Prepares WP_Filesystem without ever prompting for credentials.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( 'direct' !== get_filesystem_method( array(), dirname( self::path() ) ) ) {
			return null;
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return null;
		}

		return $wp_filesystem;
	}

	/**
	 * Why editing is not possible right now, or an empty string.
	 *
	 * @return string
	 */
	public static function unavailable_reason() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'File editing is disabled on this site (DISALLOW_FILE_EDIT), so the editor is turned off.', 'tagwright-seo' );
		}

		if ( ! self::is_apache() ) {
			return __( 'Your server is not Apache or LiteSpeed, so it does not read .htaccess files. Server rules are set elsewhere, for example in the Nginx configuration.', 'tagwright-seo' );
		}

		$fs = self::filesystem();
		if ( ! $fs ) {
			return __( 'WordPress cannot write files directly on this server, so the editor is turned off.', 'tagwright-seo' );
		}

		if ( ! $fs->exists( self::path() ) ) {
			return __( 'There is no .htaccess file in your site root yet. Saving your permalinks under Settings › Permalinks usually creates one.', 'tagwright-seo' );
		}

		if ( ! $fs->is_writable( self::path() ) ) {
			return __( 'The .htaccess file exists but is not writable. Ask your host to fix its permissions.', 'tagwright-seo' );
		}

		return '';
	}

	/**
	 * Current file content.
	 *
	 * @return string
	 */
	public static function read() {
		$fs = self::filesystem();

		if ( ! $fs || ! $fs->exists( self::path() ) ) {
			return '';
		}

		return (string) $fs->get_contents( self::path() );
	}

	/**
	 * Normalizes submitted content. Returns null when it cannot be saved.
	 *
	 * @param string $content Raw content.
	 * @return string|null
	 */
	public static function clean( $content ) {
		$content = (string) $content;

		if ( strlen( $content ) > self::MAX_SIZE ) {
			return null;
		}

		// wp_check_invalid_utf8() returns an empty string for invalid input.
		if ( '' !== $content && '' === wp_check_invalid_utf8( $content ) ) {
			return null;
		}

		$content = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $content );

		return rtrim( $content ) . "\n";
	}

	/**
	 * Whether a save would drop the block WordPress manages.
	 *
	 * @param string $old Current content.
	 * @param string $new New content.
	 * @return bool
	 */
	public static function removes_wordpress_block( $old, $new ) {
		return false !== strpos( $old, self::WP_MARKER ) && false === strpos( $new, self::WP_MARKER );
	}

	/**
	 * Saves new content.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( $request ) {
		$reason = self::unavailable_reason();
		if ( '' !== $reason ) {
			return new \WP_Error( 'tagwright_seo_htaccess_unavailable', $reason, array( 'status' => 400 ) );
		}

		$content = self::clean( (string) $request->get_param( 'content' ) );
		if ( null === $content ) {
			return new \WP_Error( 'tagwright_seo_htaccess_invalid', __( 'The content is too large or is not valid text.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		$current = self::read();

		if ( self::removes_wordpress_block( $current, $content ) && ! $request->get_param( 'confirm' ) ) {
			return new \WP_Error(
				'tagwright_seo_htaccess_confirm',
				__( 'The "# BEGIN WordPress" block is missing. Without it your permalinks stop working. Tick the confirmation box if you really mean to remove it.', 'tagwright-seo' ),
				array( 'status' => 400 )
			);
		}

		if ( $content === $current ) {
			return rest_ensure_response(
				array(
					'message' => __( 'Nothing changed.', 'tagwright-seo' ),
					'backups' => self::backups_for_display(),
				)
			);
		}

		return $this->write( $content, $current, __( '.htaccess saved. Open your site in a private window to check it still loads.', 'tagwright-seo' ) );
	}

	/**
	 * Puts a backup back.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore( $request ) {
		$reason = self::unavailable_reason();
		if ( '' !== $reason ) {
			return new \WP_Error( 'tagwright_seo_htaccess_unavailable', $reason, array( 'status' => 400 ) );
		}

		$time = (int) $request->get_param( 'time' );

		foreach ( self::backups() as $backup ) {
			if ( (int) $backup['time'] === $time ) {
				return $this->write( (string) $backup['content'], self::read(), __( 'Backup restored.', 'tagwright-seo' ) );
			}
		}

		return new \WP_Error( 'tagwright_seo_htaccess_no_backup', __( 'That backup no longer exists.', 'tagwright-seo' ), array( 'status' => 404 ) );
	}

	/**
	 * Backs up the current file and writes the new one.
	 *
	 * @param string $content New content.
	 * @param string $current Content being replaced.
	 * @param string $message Success message.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function write( $content, $current, $message ) {
		$fs = self::filesystem();
		if ( ! $fs ) {
			return new \WP_Error( 'tagwright_seo_htaccess_unavailable', __( 'WordPress cannot write files directly on this server.', 'tagwright-seo' ), array( 'status' => 500 ) );
		}

		self::add_backup( $current );

		if ( ! $fs->put_contents( self::path(), $content, FS_CHMOD_FILE ) ) {
			return new \WP_Error( 'tagwright_seo_htaccess_write', __( 'The file could not be written. Nothing was changed.', 'tagwright-seo' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'message' => $message,
				'content' => $content,
				'backups' => self::backups_for_display(),
			)
		);
	}

	/**
	 * Stored backups, newest first.
	 *
	 * @return array[] Each with time, user and content.
	 */
	public static function backups() {
		$backups = get_option( self::BACKUP_OPTION, array() );

		return is_array( $backups ) ? $backups : array();
	}

	/**
	 * Adds a version to the backups.
	 *
	 * @param string $content Content to keep.
	 * @return void
	 */
	private static function add_backup( $content ) {
		$backups = self::backups();
		$time    = time();

		// Two saves in the same second would share an id; nudge the newer one.
		if ( $backups && (int) $backups[0]['time'] >= $time ) {
			$time = (int) $backups[0]['time'] + 1;
		}

		array_unshift(
			$backups,
			array(
				'time'    => $time,
				'user'    => get_current_user_id(),
				'content' => (string) $content,
			)
		);

		update_option( self::BACKUP_OPTION, array_slice( $backups, 0, self::BACKUPS ), false );
	}

	/**
	 * Backups without their content, for the list on screen.
	 *
	 * @return array[]
	 */
	public static function backups_for_display() {
		$rows = array();

		foreach ( self::backups() as $backup ) {
			$user   = get_userdata( (int) $backup['user'] );
			$rows[] = array(
				'time'  => (int) $backup['time'],
				'date'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['time'] ),
				'user'  => $user ? $user->display_name : '',
				'lines' => substr_count( (string) $backup['content'], "\n" ),
			);
		}

		return $rows;
	}

	/**
	 * The editor inside the settings tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Modules::is_active( 'technical_tools' ) ) {
			echo '<p class="tagwright-muted">' . esc_html__( 'Available once the Technical Tools module is switched on.', 'tagwright-seo' ) . '</p>';
			return;
		}

		$reason = self::unavailable_reason();
		if ( '' === $reason && ! self::can_edit() ) {
			$reason = __( 'Only a network super admin can edit this file, because every site in the network shares it.', 'tagwright-seo' );
		}

		if ( '' !== $reason ) {
			UI::notice( 'info', $reason );
			return;
		}

		$backups = self::backups_for_display();
		?>
		<div data-tagwright-htaccess>
			<textarea class="tagwright-textarea tagwright-textarea--code tagwright-technical-tools-htaccess" rows="18" spellcheck="false" autocomplete="off" data-content aria-label="<?php esc_attr_e( '.htaccess content', 'tagwright-seo' ); ?>"><?php echo esc_textarea( self::read() ); ?></textarea>
			<p class="tagwright-field__description">
				<?php
				printf(
					/* translators: %s: file path. */
					esc_html__( 'Editing %s. Keep the "# BEGIN WordPress" block unless you know exactly why it has to go.', 'tagwright-seo' ),
					'<code>' . esc_html( self::path() ) . '</code>'
				);
				?>
			</p>
			<p class="tagwright-technical-tools-actions">
				<label class="tagwright-check">
					<input type="checkbox" data-confirm-remove />
					<span class="tagwright-check__box"><?php Icons::render( 'check', 12 ); ?></span>
					<span><?php esc_html_e( 'I understand removing the WordPress block breaks permalinks', 'tagwright-seo' ); ?></span>
				</label>
				<button type="button" class="tagwright-btn tagwright-btn--primary" data-save-htaccess><?php Icons::render( 'check', 16 ); ?><span><?php esc_html_e( 'Save .htaccess', 'tagwright-seo' ); ?></span></button>
			</p>

			<h4 class="tagwright-technical-tools-subhead"><?php esc_html_e( 'Backups', 'tagwright-seo' ); ?></h4>
			<table class="tagwright-table" data-backups<?php echo $backups ? '' : ' hidden'; ?>>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Saved', 'tagwright-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'By', 'tagwright-seo' ); ?></th>
						<th scope="col" class="is-num"><?php esc_html_e( 'Lines', 'tagwright-seo' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tagwright-seo' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups as $backup ) : ?>
						<tr>
							<td><?php echo esc_html( $backup['date'] ); ?></td>
							<td><?php echo esc_html( $backup['user'] ); ?></td>
							<td class="is-num"><?php echo esc_html( number_format_i18n( $backup['lines'] ) ); ?></td>
							<td class="is-num"><button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-restore="<?php echo esc_attr( (string) $backup['time'] ); ?>"><?php Icons::render( 'refresh', 14 ); ?><span><?php esc_html_e( 'Restore', 'tagwright-seo' ); ?></span></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="tagwright-muted" data-no-backups<?php echo $backups ? ' hidden' : ''; ?>><?php esc_html_e( 'A copy of the file is kept here every time you save, up to five versions.', 'tagwright-seo' ); ?></p>
		</div>
		<?php
	}
}
