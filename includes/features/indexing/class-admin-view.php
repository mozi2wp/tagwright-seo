<?php
/**
 * Instant Indexing admin screens.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

use TagwrightSEO\Admin\Admin;
use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Admin\UI;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The custom parts of the settings tab, the assets for it and the bulk
 * action on post lists.
 */
class Admin_View {

	/**
	 * Bulk action id.
	 */
	const BULK_ACTION = 'tagwright_seo_indexing_submit';

	/**
	 * Registers admin hooks. Only created while the module is on.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'load-edit.php', array( $this, 'register_bulk_action' ) );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_args' ) );
	}

	/**
	 * Lets WordPress tidy our result flags out of the address bar.
	 *
	 * @param string[] $args Query args.
	 * @return string[]
	 */
	public function removable_args( $args ) {
		$args[] = 'tagwright_indexing_sent';
		$args[] = 'tagwright_indexing_ok';

		return $args;
	}

	/**
	 * Loads the tab script on the settings screen only.
	 *
	 * @return void
	 */
	public function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the page slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'tagwright-seo-settings' !== $page ) {
			return;
		}

		wp_enqueue_style( 'tagwright-seo-indexing', TAGWRIGHT_SEO_URL . 'assets/features/indexing/admin.css', array( 'tagwright-seo-admin' ), TAGWRIGHT_SEO_VERSION );
		wp_enqueue_script( 'tagwright-seo-indexing', TAGWRIGHT_SEO_URL . 'assets/features/indexing/admin.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-indexing', 'tagwright-seo' );
	}

	/**
	 * Notice shown in place of the custom fields while the module is off.
	 *
	 * @return bool True when the module is off and the notice was printed.
	 */
	private static function maybe_print_off_notice() {
		if ( Modules::is_active( 'indexing' ) ) {
			return false;
		}

		echo '<p class="tagwright-muted">' . esc_html__( 'Available once the Instant Indexing module is switched on.', 'tagwright-seo' ) . '</p>';

		return true;
	}

	/**
	 * IndexNow key, its file and the regenerate button.
	 *
	 * @return void
	 */
	public static function render_key() {
		if ( self::maybe_print_off_notice() ) {
			return;
		}

		$key = Indexnow::key();
		$url = Indexnow::key_url( $key );
		?>
		<div class="tagwright-indexing-key" data-tagwright-indexing-key>
			<code data-key><?php echo esc_html( $key ); ?></code>
			<a class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" data-key-url>
				<?php Icons::render( 'external', 14 ); ?><span><?php esc_html_e( 'Open key file', 'tagwright-seo' ); ?></span>
			</a>
			<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-regenerate-key data-confirm="<?php esc_attr_e( 'Create a new key? The old key file stops working straight away.', 'tagwright-seo' ); ?>">
				<?php Icons::render( 'refresh', 14 ); ?><span><?php esc_html_e( 'Regenerate', 'tagwright-seo' ); ?></span>
			</button>
		</div>
		<p class="tagwright-field__description"><?php esc_html_e( 'Search engines fetch this file to confirm the pings come from you. It is created and served automatically.', 'tagwright-seo' ); ?></p>
		<?php
		if ( Feature::is_local_site() ) {
			UI::notice( 'info', __( 'This looks like a local or development site, so automatic pings are paused. They start on their own once the site runs on its real domain.', 'tagwright-seo' ) );
		}
	}

	/**
	 * Service account box: either the saved account or the paste field.
	 *
	 * @return void
	 */
	public static function render_google_key() {
		if ( self::maybe_print_off_notice() ) {
			return;
		}

		$email = Google::client_email();
		?>
		<div data-tagwright-indexing-google>
			<div class="tagwright-indexing-saved" data-saved<?php echo '' === $email ? ' hidden' : ''; ?>>
				<span class="tagwright-badge tagwright-badge--good"><?php Icons::render( 'key', 12 ); ?> <?php esc_html_e( 'Key saved', 'tagwright-seo' ); ?></span>
				<span data-email>
					<?php
					/* translators: %s: service account email. */
					echo esc_html( sprintf( __( 'for %s', 'tagwright-seo' ), $email ) );
					?>
				</span>
				<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-remove-google data-confirm="<?php esc_attr_e( 'Remove the saved service account key?', 'tagwright-seo' ); ?>">
					<?php Icons::render( 'trash', 14 ); ?><span><?php esc_html_e( 'Remove', 'tagwright-seo' ); ?></span>
				</button>
			</div>
			<div data-empty<?php echo '' !== $email ? ' hidden' : ''; ?>>
				<textarea class="tagwright-textarea tagwright-textarea--code" rows="6" data-google-json spellcheck="false" autocomplete="off" aria-label="<?php esc_attr_e( 'Service account JSON key', 'tagwright-seo' ); ?>" placeholder="<?php echo esc_attr( '{ "type": "service_account", "client_email": "…", "private_key": "…" }' ); ?>"></textarea>
				<p>
					<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-save-google>
						<?php Icons::render( 'check', 14 ); ?><span><?php esc_html_e( 'Save key', 'tagwright-seo' ); ?></span>
					</button>
				</p>
			</div>
			<p class="tagwright-field__description">
				<?php esc_html_e( 'Paste the JSON key file of a Google Cloud service account with the Indexing API enabled, then add its email as an owner of your property in Search Console. The key is stored separately from your other settings, is never shown again and is not included in settings exports.', 'tagwright-seo' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Manual submission form.
	 *
	 * @return void
	 */
	public static function render_manual() {
		if ( self::maybe_print_off_notice() ) {
			return;
		}
		?>
		<div data-tagwright-indexing-manual>
			<textarea class="tagwright-textarea" rows="6" data-urls spellcheck="false" aria-label="<?php esc_attr_e( 'URLs to submit, one per line', 'tagwright-seo' ); ?>" placeholder="<?php echo esc_attr( home_url( '/example-post/' ) ); ?>"></textarea>
			<p>
				<button type="button" class="tagwright-btn tagwright-btn--primary" data-submit-urls>
					<?php Icons::render( 'upload', 16 ); ?><span><?php esc_html_e( 'Submit now', 'tagwright-seo' ); ?></span>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Recent submissions table.
	 *
	 * @return void
	 */
	public static function render_log() {
		$rows = Log::for_display();
		?>
		<div data-tagwright-indexing-log>
			<table class="tagwright-table" data-log-table<?php echo $rows ? '' : ' hidden'; ?>>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'tagwright-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Engine', 'tagwright-seo' ); ?></th>
						<th scope="col" class="is-num"><?php esc_html_e( 'URLs', 'tagwright-seo' ); ?></th>
						<th scope="col" class="is-num"><?php esc_html_e( 'Status', 'tagwright-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'tagwright-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['time'] ); ?></td>
							<td><?php echo esc_html( $row['engine'] ); ?></td>
							<td class="is-num"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
							<td class="is-num"><span class="tagwright-badge tagwright-badge--<?php echo $row['ok'] ? 'good' : 'bad'; ?>"><?php echo esc_html( $row['status'] ? (string) $row['status'] : '—' ); ?></span></td>
							<td><?php echo esc_html( $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="tagwright-muted" data-log-empty<?php echo $rows ? ' hidden' : ''; ?>><?php esc_html_e( 'Nothing submitted yet.', 'tagwright-seo' ); ?></p>
			<p data-log-actions<?php echo $rows ? '' : ' hidden'; ?>>
				<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-clear-log><?php Icons::render( 'trash', 14 ); ?><span><?php esc_html_e( 'Clear log', 'tagwright-seo' ); ?></span></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Adds the bulk action on list screens of the chosen post types.
	 *
	 * @return void
	 */
	public function register_bulk_action() {
		if ( ! Options::get( 'indexing_bulk_action' ) || ! current_user_can( Admin::capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! Feature::is_enabled_type( $screen->post_type ) ) {
			return;
		}

		add_filter( 'bulk_actions-' . $screen->id, array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-' . $screen->id, array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Adds the entry to the dropdown.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $actions ) {
		$actions[ self::BULK_ACTION ] = __( 'Submit to search engines', 'tagwright-seo' );

		return $actions;
	}

	/**
	 * Sends the selected posts. WordPress has checked the bulk-posts nonce
	 * before this runs; the capability is checked again here because the
	 * request could come from a crafted form.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Chosen action.
	 * @param int[]  $post_ids Selected posts.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect;
		}

		$redirect = remove_query_arg( array( 'tagwright_indexing_sent', 'tagwright_indexing_ok' ), $redirect );

		if ( ! current_user_can( Admin::capability() ) ) {
			return $redirect;
		}

		$items = array();
		foreach ( array_slice( array_map( 'absint', (array) $post_ids ), 0, Rest::MANUAL_LIMIT ) as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status && empty( $post->post_password ) && ! Feature::is_noindex( $post ) ) {
				$items[ (string) get_permalink( $post ) ] = 'update';
			}
		}

		$ok = false;
		if ( $items ) {
			$results = Queue::send( $items );
			$ok      = $results && ! in_array( false, wp_list_pluck( $results, 'ok' ), true );
		}

		return add_query_arg(
			array(
				'tagwright_indexing_sent' => count( $items ),
				'tagwright_indexing_ok'   => $ok ? 1 : 0,
			),
			$redirect
		);
	}

	/**
	 * Result notice after the bulk action.
	 *
	 * @return void
	 */
	public function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flags set by our own redirect.
		if ( ! isset( $_GET['tagwright_indexing_sent'] ) ) {
			return;
		}

		$count = absint( $_GET['tagwright_indexing_sent'] );
		$ok    = ! empty( $_GET['tagwright_indexing_ok'] );
		// phpcs:enable

		if ( ! $count ) {
			$class   = 'notice-warning';
			$message = __( 'Nothing was submitted: only published, indexable items can be sent to search engines.', 'tagwright-seo' );
		} elseif ( $ok ) {
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %d: number of URLs. */
				_n( '%d URL submitted to search engines.', '%d URLs submitted to search engines.', $count, 'tagwright-seo' ),
				$count
			);
		} else {
			$class   = 'notice-error';
			$message = __( 'Some submissions failed. See Tagwright SEO › Settings › Instant Indexing for details.', 'tagwright-seo' );
		}

		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}
}
