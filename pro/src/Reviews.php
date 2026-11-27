<?php
/**
 * Reviews: a status and a note for each plugin or theme with something to look at.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * A scan says what is wrong. A review says what you decided: "we're fixing it", "waiting for the
 * developer", or "accepted, we're leaving it". It is a record kept on your site, with a note, who
 * wrote it and when; it never changes what Airworthy finds. A review belongs to the version you
 * looked at: when the plugin is updated the review is marked out of date, not carried over.
 * A note can be shown in the client report, but only if you tick that for it, because agency notes
 * are often meant for the team.
 */
final class Reviews {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'reviews';

	/** Option (not autoloaded) holding the reviews. */
	const OPTION = 'airworthy_pro_reviews';

	/** Admin-post action name. */
	const SAVE = 'airworthy_pro_review_save';

	/** Longest note, in characters. */
	const NOTE_MAX = 600;

	/**
	 * Hooks the tab and its form handler.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 9 );
		add_filter( 'airworthy_admin_tab_icons', array( __CLASS__, 'tab_icon' ) );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Reviews', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's icon.
	 *
	 * @param array $icons slug => Dashicons class.
	 * @return array
	 */
	public static function tab_icon( $icons ) {
		$icons[ self::TAB ] = 'dashicons-yes-alt';
		return $icons;
	}

	/**
	 * The tab's address.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'tab' => self::TAB ), $args ), \Airworthy\Admin\Page::url() );
	}

	/**
	 * Pro's styles on this tab.
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which styles to load.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || \Airworthy\Admin\Page::SLUG !== $_GET['page'] || self::TAB !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_style( 'airworthy-pro-admin', AIRWORTHY_PRO_URL . 'assets/admin.css', array(), AIRWORTHY_PRO_VERSION . '.' . (int) filemtime( AIRWORTHY_PRO_DIR . 'assets/admin.css' ) );
	}

	// ── Data ───────────────────────────────────────────────────────────────────────────────────

	/**
	 * The statuses a review can have.
	 *
	 * @return array<string,array{0:string,1:string}> key => symbol and label.
	 */
	public static function statuses() {
		return array(
			'fixing'   => array( '🔧', __( 'We are fixing it', 'airworthy-pro' ) ),
			'waiting'  => array( '⏳', __( 'Waiting for the developer', 'airworthy-pro' ) ),
			'accepted' => array( '✅', __( 'Accepted: we are leaving it', 'airworthy-pro' ) ),
		);
	}

	/**
	 * Every saved review, keyed "type|slug".
	 *
	 * @return array[]
	 */
	public static function all() {
		$all = get_site_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * One review, or null.
	 *
	 * @param string $type plugin | theme.
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function get( $type, $slug ) {
		$all = self::all();
		$key = $type . '|' . $slug;
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Saves a review from form values. An empty status removes it.
	 *
	 * @param array $in type, slug, status, note, client (bool), version, verdict, target.
	 * @param int   $by User ID.
	 * @return bool Whether a review is saved (false when cleared or the input is invalid).
	 */
	public static function save( array $in, $by ) {
		$type = isset( $in['type'] ) && 'theme' === $in['type'] ? 'theme' : 'plugin';
		$slug = isset( $in['slug'] ) ? sanitize_text_field( (string) $in['slug'] ) : '';
		if ( '' === $slug ) {
			return false;
		}
		$all    = self::all();
		$key    = $type . '|' . $slug;
		$status = isset( $in['status'] ) ? (string) $in['status'] : '';
		if ( ! isset( self::statuses()[ $status ] ) ) {
			unset( $all[ $key ] );
			self::store( $all );
			return false;
		}
		$note        = isset( $in['note'] ) ? sanitize_textarea_field( (string) $in['note'] ) : '';
		$all[ $key ] = array(
			'status'  => $status,
			'note'    => mb_substr( $note, 0, self::NOTE_MAX ),
			'client'  => ! empty( $in['client'] ),
			'version' => isset( $in['version'] ) ? sanitize_text_field( (string) $in['version'] ) : '',
			'verdict' => isset( $in['verdict'] ) ? sanitize_key( (string) $in['verdict'] ) : '',
			'target'  => isset( $in['target'] ) ? sanitize_text_field( (string) $in['target'] ) : '',
			'by'      => (int) $by,
			'at'      => time(),
		);
		self::store( $all );
		return true;
	}

	/**
	 * Writes the reviews.
	 *
	 * @param array[] $all Reviews.
	 */
	private static function store( array $all ) {
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $all );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Whether a review is still about the version that is installed.
	 *
	 * @param array  $review  Review.
	 * @param string $version Installed version.
	 * @return bool
	 */
	public static function is_current( array $review, $version ) {
		return (string) $review['version'] === (string) $version;
	}

	/**
	 * What the client report may say about a plugin or theme: its status and note, only when the
	 * review is for the installed version and its note was marked for the client.
	 *
	 * @param array $component Component from the scan: type, slug, version.
	 * @return string Empty when there is nothing to show.
	 */
	public static function client_note( array $component ) {
		$review = self::get( isset( $component['type'] ) ? (string) $component['type'] : 'plugin', (string) $component['slug'] );
		if ( ! $review || empty( $review['client'] ) || ! self::is_current( $review, isset( $component['version'] ) ? (string) $component['version'] : '' ) ) {
			return '';
		}
		$status = self::statuses()[ $review['status'] ];
		$text   = $status[0] . ' ' . $status[1];
		if ( '' !== $review['note'] ) {
			$text .= ': ' . $review['note'];
		}
		return $text;
	}

	/**
	 * The plugins and themes in the latest scan that have something to look at.
	 *
	 * @return array{scan:object|null,rows:object[]}
	 */
	public static function items() {
		global $wpdb;
		$t    = Installer::tables();
		$id   = Scans::latest_id();
		$scan = null;
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
			$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, target_php FROM %i WHERE id = %d', $t['scans'], $id ) );
		}
		if ( ! $scan ) {
			return array(
				'scan' => null,
				'rows' => array(),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, slug, name, version, verdict FROM %i WHERE scan_id = %d AND status = 'done' AND verdict IN ( 'blocker', 'unknown', 'suppressed', 'warnings' ) ORDER BY FIELD( verdict, 'blocker', 'unknown', 'suppressed', 'warnings' ), name LIMIT 200", $t['components'], $scan->id ) );
		return array(
			'scan' => $scan,
			'rows' => (array) $rows,
		);
	}

	/**
	 * How many of the items are open, in progress, accepted and out of date.
	 *
	 * @param object[] $rows Rows from items().
	 * @return array{open:int,fixing:int,waiting:int,accepted:int,stale:int}
	 */
	public static function counts( array $rows ) {
		$out = array(
			'open'     => 0,
			'fixing'   => 0,
			'waiting'  => 0,
			'accepted' => 0,
			'stale'    => 0,
		);
		foreach ( $rows as $row ) {
			$review = self::get( $row->type, $row->slug );
			if ( ! $review ) {
				++$out['open'];
			} elseif ( ! self::is_current( $review, $row->version ) ) {
				++$out['stale'];
			} else {
				++$out[ $review['status'] ];
			}
		}
		return $out;
	}

	// ── The tab ────────────────────────────────────────────────────────────────────────────────

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$data = self::items();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a saved message.
		$notice   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$counts   = self::counts( $data['rows'] );
		$statuses = self::statuses();
		?>
		<div class="airworthy-card airworthy-pro-reviews">
			<h2>✅ <?php esc_html_e( 'Reviews', 'airworthy-pro' ); ?></h2>
			<p class="airworthy-muted"><?php esc_html_e( 'A scan says what is wrong. Here you record what you decided about each plugin or theme with something to look at: you are fixing it, you are waiting for the developer, or you have looked and are leaving it. Add a note so the next person, or you in six months, knows why. A review belongs to the version you looked at: when the plugin is updated, it is marked out of date so it gets looked at again. Reviews never change what Airworthy finds.', 'airworthy-pro' ); ?></p>
			<?php if ( 'saved' === $notice ) : ?>
				<p class="airworthy-note" role="status"><?php esc_html_e( 'Saved.', 'airworthy-pro' ); ?></p>
			<?php elseif ( 'cleared' === $notice ) : ?>
				<p class="airworthy-note" role="status"><?php esc_html_e( 'Review removed.', 'airworthy-pro' ); ?></p>
			<?php endif; ?>
			<?php if ( ! $data['scan'] ) : ?>
				<p class="airworthy-note"><?php esc_html_e( 'Run a scan first on the Scan tab. The list comes from its results.', 'airworthy-pro' ); ?></p>
			<?php elseif ( ! $data['rows'] ) : ?>
				<p class="airworthy-note">✅ <?php esc_html_e( 'Nothing to review: no plugin or theme in your last scan has problems or unchecked files.', 'airworthy-pro' ); ?></p>
			<?php else : ?>
				<ul class="airworthy-pro-facts">
					<li><span aria-hidden="true">📋</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d to review', '%d to review', $counts['open'], 'airworthy-pro' ), $counts['open'] ) ); ?></li>
					<li><span aria-hidden="true">🔧</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ __( '%d being fixed', 'airworthy-pro' ), $counts['fixing'] ) ); ?></li>
					<li><span aria-hidden="true">⏳</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ __( '%d waiting for a developer', 'airworthy-pro' ), $counts['waiting'] ) ); ?></li>
					<li><span aria-hidden="true">✅</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ __( '%d accepted', 'airworthy-pro' ), $counts['accepted'] ) ); ?></li>
					<?php if ( $counts['stale'] ) : ?>
						<li class="is-warn"><span aria-hidden="true">🔄</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d review is out of date (the plugin was updated)', '%d reviews are out of date (the plugins were updated)', $counts['stale'], 'airworthy-pro' ), $counts['stale'] ) ); ?></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php foreach ( $data['rows'] as $row ) : ?>
			<?php
			$review = self::get( $row->type, $row->slug );
			$stale  = $review && ! self::is_current( $review, $row->version );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-card airworthy-pro-review-row">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>" />
				<?php wp_nonce_field( self::SAVE ); ?>
				<input type="hidden" name="type" value="<?php echo esc_attr( $row->type ); ?>" />
				<input type="hidden" name="slug" value="<?php echo esc_attr( $row->slug ); ?>" />
				<input type="hidden" name="version" value="<?php echo esc_attr( $row->version ); ?>" />
				<input type="hidden" name="verdict" value="<?php echo esc_attr( $row->verdict ); ?>" />
				<input type="hidden" name="target" value="<?php echo esc_attr( $data['scan']->target_php ); ?>" />
				<h3>
					<?php echo esc_html( $row->name ); ?>
					<span class="airworthy-muted"><?php echo esc_html( $row->version ? 'v' . $row->version : '' ); ?></span>
					<span class="airworthy-pro-pill is-off"><?php echo esc_html( Alerts::VERDICTS[ $row->verdict ][0] . ' ' . Alerts::VERDICTS[ $row->verdict ][1] ); ?></span>
				</h3>
				<?php if ( $stale ) : ?>
					<p class="airworthy-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: version reviewed, 2: installed version. */
								__( 'Out of date: this review is for version %1$s, and %2$s is installed now. Look again, then save.', 'airworthy-pro' ),
								$review['version'],
								$row->version
							)
						);
						?>
					</p>
				<?php elseif ( $review ) : ?>
					<?php $user = get_userdata( (int) $review['by'] ); ?>
					<p class="airworthy-muted">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: person, 2: date. */
								__( 'Last saved by %1$s on %2$s.', 'airworthy-pro' ),
								$user ? $user->display_name : __( 'someone', 'airworthy-pro' ),
								wp_date( get_option( 'date_format' ), (int) $review['at'] )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p>
					<label for="aw-status-<?php echo esc_attr( md5( $row->type . $row->slug ) ); ?>" class="screen-reader-text"><?php esc_html_e( 'Status', 'airworthy-pro' ); ?></label>
					<select id="aw-status-<?php echo esc_attr( md5( $row->type . $row->slug ) ); ?>" name="status">
						<option value=""><?php esc_html_e( 'Not reviewed yet', 'airworthy-pro' ); ?></option>
						<?php foreach ( $statuses as $key => $st ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $review ? $review['status'] : '', $key ); ?>><?php echo esc_html( $st[0] . ' ' . $st[1] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="aw-note-<?php echo esc_attr( md5( $row->type . $row->slug ) ); ?>" class="screen-reader-text"><?php esc_html_e( 'Note', 'airworthy-pro' ); ?></label>
					<textarea id="aw-note-<?php echo esc_attr( md5( $row->type . $row->slug ) ); ?>" name="note" rows="2" class="large-text" maxlength="<?php echo (int) self::NOTE_MAX; ?>" placeholder="<?php esc_attr_e( 'Why? For example: "Only used on the old events page, which we are retiring in March."', 'airworthy-pro' ); ?>"><?php echo esc_textarea( $review ? $review['note'] : '' ); ?></textarea>
				</p>
				<p>
					<label class="airworthy-choice"><input type="checkbox" name="client" value="1" <?php checked( $review && ! empty( $review['client'] ) ); ?> /> <span><?php esc_html_e( 'Show this status and note in the client report', 'airworthy-pro' ); ?></span></label>
				</p>
				<p class="airworthy-pro-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'airworthy-pro' ); ?></button></p>
			</form>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Saves one review from the form.
	 */
	public static function handle_save() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::SAVE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; every value is cleaned by save().
		$in = array(
			'type'    => isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : '',
			'slug'    => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
			'status'  => isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : '',
			'note'    => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
			'client'  => ! empty( $_POST['client'] ),
			'version' => isset( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : '',
			'verdict' => isset( $_POST['verdict'] ) ? wp_unslash( $_POST['verdict'] ) : '',
			'target'  => isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : '',
		);
		// phpcs:enable
		$saved = self::save( $in, get_current_user_id() );
		wp_safe_redirect( self::url( array( 'notice' => $saved ? 'saved' : 'cleared' ) ) );
		exit;
	}
}
