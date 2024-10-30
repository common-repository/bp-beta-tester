<?php
/**
 * Functions.
 *
 * @package   bp-beta-tester
 * @subpackage \inc\functions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sort Callback for BuddyPress versions.
 *
 * @since 1.0.0
 *
 * @param string $a The BuddyPress version to compare.
 * @param string $b The BuddyPress version to compare with.
 * @return integer 1 if $a < $b, -1 otherwise.
 */
function bp_beta_tester_sort_versions( $a, $b ) {
	$is_lower = -1;
	if ( version_compare( $a, $b, '<' ) ) {
		$is_lower = 1;
	}

	return $is_lower;
}

/**
 * Get the url to get BuddyPress updates.
 *
 * @since 1.0.0
 */
function bp_beta_tester_get_updates_url() {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'upgrade-plugin',
				'plugin' => 'buddypress/bp-loader.php',
			),
			self_admin_url( 'update.php' )
		),
		'upgrade-plugin_buddypress/bp-loader.php'
	);
}

/**
 * Get the new site transient to use to get the requested version.
 *
 * @since 1.0.0
 *
 * @param  object|WP_Error $api     The Plugins API information about BuddyPress or WP_Error.
 * @param  string          $version The version to use for this upgrade or downgrade.
 * @return object                   The site transient to use for this upgrade.
 */
function bp_beta_tester_get_version( $api = null, $version = '' ) {
	$new_transient = null;

	if ( is_wp_error( $api ) || ! $api || ! $version ) {
		return $new_transient;
	}

	$updates     = get_site_transient( 'update_plugins' );
	$plugin_file = 'buddypress/bp-loader.php';

	if ( ! isset( $updates->response ) ) {
		$updates->response = array();
	}

	if ( ! isset( $updates->response[ $plugin_file ] ) ) {
		$icons = array();
		if ( isset( $api->icons ) ) {
			$icons = $api->icons;
		}

		$banners = array();
		if ( isset( $api->banners ) ) {
			$banners = $api->banners;
		}

		$banners_rtl = array();
		if ( isset( $api->banners_rtl ) ) {
			$banners_rtl = $api->banners_rtl;
		} else {
			$banners_rtl = $banners;
		}

		$updates->response[ $plugin_file ] = (object) array(
			'id'             => 'w.org/plugins/buddypress',
			'slug'           => 'buddypress',
			'plugin'         => $plugin_file,
			'new_version'    => $version,
			'url'            => 'https://wordpress.org/plugins/buddypress/',
			'package'        => $api->versions[ $version ],
			'icons'          => $icons,
			'banners'        => $banners,
			'banners_rtl'    => $banners_rtl,
			'upgrade_notice' => '',
		);

		$new_transient = $updates;
	} elseif ( isset( $updates->response[ $plugin_file ]->new_version ) && $version !== $updates->response[ $plugin_file ]->new_version ) {
		$updates->response[ $plugin_file ]->new_version    = $version;
		$updates->response[ $plugin_file ]->package        = $api->versions[ $version ];
		$updates->response[ $plugin_file ]->upgrade_notice = '';

		$new_transient = $updates;
	}

	return $new_transient;
}

/**
 * Get information about BuddyPress during page load & handle revert if needed.
 *
 * @since 1.0.0
 */
function bp_beta_tester_admin_load() {
	include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

	$bpbt      = bp_beta_tester();
	$bpbt->api = plugins_api(
		'plugin_information',
		array(
			'slug'   => 'buddypress',
			'fields' => array(
				'tags'        => true,
				'icons'       => true,
				'banners_rtl' => true,
				'sections'    => false,
			),
		)
	);

	if ( ! is_wp_error( $bpbt->api ) && isset( $_GET['action'] ) && 'restore-stable' === $_GET['action'] ) {
		check_admin_referer( 'restore_stable_buddypress' );

		$stable = '';
		if ( isset( $_GET['stable'] ) ) {
			$stable = wp_unslash( $_GET['stable'] ); // phpcs:ignore
		}

		if ( isset( $bpbt->api->versions[ $stable ] ) ) {
			$plugin_file   = 'buddypress/bp-loader.php';
			$new_transient = bp_beta_tester_get_version( $bpbt->api, $stable );

			if ( ! is_null( $new_transient ) ) {
				set_site_transient( 'bp_beta_tester_pre_release', $new_transient );

				// We need to do this to make sure the redirect works as expected.
				$redirect_url = str_replace( '&amp;', '&', bp_beta_tester_get_updates_url() );

				wp_safe_redirect( $redirect_url );
				exit();
			}
		}
	}
}

/**
 * Override the update_plugins transient if needed.
 *
 * @since 1.0.0
 *
 * @param boolean $transient False.
 * @return boolean|object    False or the overriden transient.
 */
function bp_beta_tester_reset_update_plugins( $transient = false ) {
	$pre_release = get_site_transient( 'bp_beta_tester_pre_release' );

	if ( isset( $pre_release->response['buddypress/bp-loader.php'] ) ) {
		$transient = $pre_release;
	}

	return $transient;
}
add_filter( 'pre_site_transient_update_plugins', 'bp_beta_tester_reset_update_plugins' );

/**
 * Deletes the pre release transient.
 *
 * @since 1.2.0
 */
function bp_beta_tester_delete_pre_release_transient() {
	$pre_release = get_site_transient( 'bp_beta_tester_pre_release' );

	if ( isset( $pre_release->response['buddypress/bp-loader.php'] ) && isset( $pre_release->response['buddypress/bp-loader.php']->new_version ) ) {
		$plugin_data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . 'buddypress/bp-loader.php', false, false );

		if ( isset( $plugin_data['Version'] ) && version_compare( $pre_release->response['buddypress/bp-loader.php']->new_version, $plugin_data['Version'], '=' ) ) {
			delete_site_transient( 'bp_beta_tester_pre_release' );
		}
	}
}

/**
 * Make sure to delete the transient once the pre-release is installed.
 *
 * @since 1.0.0
 * @since 1.2.0 Now Hooks to `upgrader_process_complete` instead of `admin_footer-update.php`.
 *
 * @param Plugin_Upgrader $upgrader The WP Core class used for upgrading/installing plugins.
 */
function bp_beta_tester_clean_pre_release_transient( $upgrader = null ) {
	if ( isset( $upgrader->result['destination_name'] ) && 'buddypress' === $upgrader->result['destination_name'] ) {
		bp_beta_tester_delete_pre_release_transient();
	}
}
add_action( 'upgrader_process_complete', 'bp_beta_tester_clean_pre_release_transient', 9, 1 );

/**
 * Register and enqueue admin style.
 *
 * @since 1.0.0
 */
function bp_beta_tester_enqueue_style() {
	$bpbt = bp_beta_tester();

	wp_register_style(
		'bp-beta-tester',
		$bpbt->css_url . 'style.css',
		array(),
		$bpbt->version
	);

	wp_enqueue_style( 'bp-beta-tester' );
}
add_action( 'admin_enqueue_scripts', 'bp_beta_tester_enqueue_style' );

/**
 * Display the Dashboard submenu page.
 *
 * @since 1.0.0
 */
function bp_beta_tester_admin_page() {
	$bpbt             = bp_beta_tester();
	$latest           = '';
	$new_transient    = null;
	$is_latest_stable = false;
	$installed        = array();
	$action           = '';
	$url              = '';

	// Disable the override.
	remove_filter( 'pre_site_transient_update_plugins', 'bp_beta_tester_reset_update_plugins' );

	if ( isset( $bpbt->api ) && $bpbt->api ) {
		$api = $bpbt->api;
	} else {
		$api = new WP_Error( 'unavailable_plugins_api', __( 'The Plugins API is unavailable.', 'bp_beta_tester' ) );
	}

	if ( ! is_wp_error( $api ) ) {
		$versions = $api->versions;

		// Sort versions so that latest are first.
		uksort( $versions, 'bp_beta_tester_sort_versions' );

		$releases         = array_keys( $versions );
		$latest           = reset( $releases );
		$is_latest_stable = false === strpos( $latest, '-' );
		$plugin_file      = 'buddypress/bp-loader.php';
		$revert           = array();

		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			$installed              = get_plugin_data( WP_PLUGIN_DIR . '/buddypress/bp-loader.php', false, false );
			$installed['is_stable'] = false === strpos( $installed['Version'], '-' );
			$installed['is_older']  = version_compare( $installed['Version'], $latest, '<' );
		}

		if ( ! $installed ) {
			$action = sprintf(
				/* translators: the %s placeholder is for the BuddyPress release tag. */
				__( 'Install %s', 'bp-beta-tester' ),
				$latest
			);

			if ( current_user_can( 'install_plugins' ) ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'         => 'install-plugin',
							'plugin'         => 'buddypress',
							'bp-beta-tester' => $latest,
						),
						self_admin_url( 'update.php' )
					),
					'install-plugin_buddypress'
				);
			}
		} elseif ( isset( $installed['Version'] ) ) {
			$action = sprintf(
				/* translators: the %s placeholder is for the BuddyPress release tag. */
				__( 'Upgrade to %s', 'bp-beta-tester' ),
				$latest
			);

			if ( $is_latest_stable && $installed['is_older'] ) {
				$url = self_admin_url( 'update-core.php' );
			} elseif ( ! $installed['is_stable'] ) {
				// Find the first stable version to be able to switch to it.
				foreach ( $versions as $version => $package ) {
					if ( false === strpos( $version, '-' ) ) {
						$revert = array(
							'url'     => wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'restore-stable',
										'page'   => 'bp-beta-tester',
										'stable' => $version,
									),
									self_admin_url( 'index.php' )
								),
								'restore_stable_buddypress'
							),
							'version' => $version,
						);
						break;
					}
				}
			}

			if ( ! $is_latest_stable && $installed['is_older'] ) {
				$url = bp_beta_tester_get_updates_url();

				$new_transient = bp_beta_tester_get_version( $api, $latest );

				if ( ! is_null( $new_transient ) ) {
					set_site_transient( 'bp_beta_tester_pre_release', $new_transient );
				}
			}

			if ( ! current_user_can( 'update_plugins' ) ) {
				$url    = '';
				$revert = array();
			}
		}
	}

	$has_upgrade_tab   = $new_transient || ( $is_latest_stable && isset( $installed['is_older'] ) && $installed['is_older'] ) || ! $installed;
	$has_downgrade_tab = isset( $revert['url'] ) && $revert['url'];
	?>
	<div class="bp-beta-tester-header">
		<div class="bp-beta-tester-title-section">
			<h1><?php esc_html_e( 'Beta Test BuddyPress', 'bp-beta-tester' ); ?></h1>
			<div class="bp-beta-tester-logo">
				<span class="dashicons dashicons-buddicons-buddypress-logo"></span>
			</div>
		</div>
		<nav class="bp-beta-tester-tabs-wrapper <?php echo ! $has_downgrade_tab || ! $has_upgrade_tab ? 'one-col' : 'two-cols'; ?>" aria-label="<?php esc_html_e( 'Main actions', 'bp-beta-tester' ); ?>">
			<?php if ( $has_upgrade_tab && $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" class="bp-beta-tester-tab active">
					<?php echo esc_html( $action ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $has_downgrade_tab ) : ?>
				<a href="<?php echo esc_url( $revert['url'] ); ?>" class="bp-beta-tester-tab">
					<?php
					printf(
						/* translators: the %s placeholder is for the BuddyPress release tag. */
						esc_html__( 'Downgrade to %s', 'bp-beta-tester' ),
						esc_html( $revert['version'] )
					);
					?>
				</a>
			<?php endif; ?>
		</nav>
	</div>
	<hr class="wp-header-end">
	<div class="bp-beta-tester-body">

		<?php if ( is_wp_error( $api ) ) : ?>

			<div id="message" class="notice notice-error">
				<p><?php echo wp_kses( $api->get_error_message(), array( 'a' => array( 'href' => true ) ) ); ?></p>
			</div>

		<?php else : ?>

			<h2 class="thanks">
				<?php
				printf(
					/* translators: %1$s is the current user display name and %2$s is a heart dashicon. */
					esc_html__( 'Thank you so much %1$s %2$s', 'bp-beta-tester' ),
					esc_html( wp_get_current_user()->display_name ),
					'<span class="dashicons dashicons-heart"></span>'
				);
				?>
			</h2>

			<p><?php esc_html_e( 'Thanks for contributing to BuddyPress: beta testing the plugin is very important to make sure it behaves the right way for you and for the community.', 'bp-beta-tester' ); ?></p>
			<p><?php esc_html_e( 'Although the BuddyPress Core Development Team is regularly testing it, it\'s very challenging to test every possible configuration of WordPress and BuddyPress.', 'bp-beta-tester' ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s is the link to the WP Core Contributor handbook page about installing WordPress locally. */
					esc_html__( 'Please make sure to avoid using this plugin on a production site: beta testing is always safer when it\'s done on a %s of your site or on a testing site.', 'bp-beta-tester' ),
					'<a href="' . esc_url( 'https://make.wordpress.org/core/handbook/tutorials/installing-wordpress-locally/' ) . '">' . esc_html__( 'local copy', 'bp-beta-tester' ) . '</a>'
				);
				?>
			</p>

			<?php if ( $is_latest_stable ) : ?>
				<p>
					<?php
					printf(
						/* translators: %1$s is the link to the BuddyPress account on Twitter and %2$s is the link to the BuddyPress blog. */
						esc_html__( 'There is no pre-releases to test currently. Please consider following BuddyPress %1$s or checking %2$s regularly to be informed of the next pre-releases.', 'bp-beta-tester' ),
						'<a href="' . esc_url( 'https://twitter.com/BuddyPress' ) . '">' . esc_html__( 'on Twitter', 'bp-beta-tester' ) . '</a>',
						'<a href="' . esc_url( 'https://buddypress.org/blog/' ) . '">' . esc_html__( 'our blog', 'bp-beta-tester' ) . '</a>'
					);
					?>
				</p>
			<?php elseif ( isset( $installed['is_stable'] ) && ! $installed['is_stable'] ) : ?>
				<h2><?php esc_html_e( 'Have you found a bug or a possible improvement?', 'bp-beta-tester' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %1$s is the link to the BuddyPress Trac and %2$s is the link to the BuddyPress Support forums. */
						esc_html__( 'Please let us know about it opening a new ticket on our %1$s or posting a new topic in our %2$s.', 'bp-beta-tester' ),
						'<a href="' . esc_url( 'https://buddypress.trac.wordpress.org/newticket' ) . '">' . esc_html__( 'Development Tracker', 'bp-beta-tester' ) . '</a>',
						'<a href="' . esc_url( 'https://buddypress.org/support/' ) . '">' . esc_html__( 'support forums', 'bp-beta-tester' ) . '</a>'
					);
					?>
				</p>
				<p><?php esc_html_e( 'One of the Core Developers/Support forum moderators will review your feedback and we\'ll do our best to fix it before the stable version is made available to the public.', 'bp-beta-tester' ); ?></p>

				<?php if ( bp_beta_tester_version_has_dev_notes( $latest ) ) : ?>
					<h2><?php esc_html_e( 'What to expect from next release?', 'bp-beta-tester' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s is the link to next release development notes. */
							esc_html__( 'We wrote some development notes about it. Please, make sure to check them on %s.', 'bp-beta-tester' ),
							'<a href="' . esc_url( bp_beta_tester_get_version_dev_notes_url( $latest ) ) . '">' . esc_html__( 'our Development Blog', 'bp-beta-tester' ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Filter Plugin API arguments to eventually include the tags field.
 *
 * @since 1.0.0
 *
 * @param object $args   Plugin API arguments.
 * @param string $action The type of information being requested from the Plugin API.
 * @return object        The Plugin API arguments.
 */
function bp_beta_tester_plugins_api_args( $args = null, $action = '' ) {
	if ( 'plugin_information' !== $action || ! isset( $args->slug ) ) {
		return $args;
	}

	if ( 'buddypress' === $args->slug ) {
		$bpbt             = bp_beta_tester();
		$bpbt->beta_or_rc = '';

		if ( isset( $_GET['bp-beta-tester'] ) && $_GET['bp-beta-tester'] ) { // phpcs:ignore
			$bpbt->beta_or_rc = wp_unslash( $_GET['bp-beta-tester'] ); // phpcs:ignore
		}

		if ( $bpbt->beta_or_rc ) {
			$args->fields = array_merge( $args->fields, array( 'tags' => true ) );
		}
	}

	return $args;
}
add_filter( 'plugins_api_args', 'bp_beta_tester_plugins_api_args', 10, 2 );

/**
 * Filter the Plugin API response results to eventually override the download link.
 *
 * @since 1.0.0
 *
 * @param object|WP_Error $res    Response object or WP_Error.
 * @param string          $action The type of information being requested from the Plugin API.
 * @param object          $args   Plugin API arguments.
 * @return object|WP_Error        The Plugin API response or WP_error.
 */
function bp_beta_tester_plugins_api( $res = null, $action = '', $args = array() ) {
	if ( is_wp_error( $res ) || 'plugin_information' !== $action || 'buddypress' !== $res->slug ) {
		return $res;
	}

	$bpbt       = bp_beta_tester();
	$beta_or_rc = '';

	if ( isset( $bpbt->beta_or_rc ) && $bpbt->beta_or_rc ) {
		$beta_or_rc = $bpbt->beta_or_rc;
		unset( $bpbt->beta_or_rc );
	}

	if ( $beta_or_rc && isset( $res->versions ) ) {
		if ( isset( $res->versions[ $beta_or_rc ] ) ) {
			$res->download_link = $res->versions[ $beta_or_rc ];
			$res->version       = $beta_or_rc;

		} else {
			return new WP_Error(
				'invalid_version',
				sprintf(
					/* translators: the %s placeholder is for the BuddyPress release tag. */
					esc_html__( 'The BuddyPress version %s is not available on WordPress.org.', 'bp-beta-tester' ),
					esc_html( $beta_or_rc )
				)
			);
		}
	}

	return $res;
}
add_filter( 'plugins_api_result', 'bp_beta_tester_plugins_api', 10, 3 );

/**
 * Gets a version development notes URL.
 *
 * @since 1.1.0
 *
 * @param string $version The version to get development notes for.
 * @return string The version development notes URL.
 */
function bp_beta_tester_get_version_dev_notes_url( $version = '' ) {
	$version = (float) $version;

	if ( ! $version ) {
		return false;
	}

	// Categories are using an hyphen instead of a dot on BP Devel.
	$version = number_format( $version, 1, '-', '' );

	return sprintf( 'https://bpdevel.wordpress.com/category/development-notes/%s/', esc_attr( $version ) );
}

/**
 * Checks if some development notes are available on BP Devel.
 *
 * @since 1.1.0
 *
 * @param string $version The version to check develompent notes for.
 * @return boolean True if there are some develompent notes. False otherwise.
 */
function bp_beta_tester_version_has_dev_notes( $version = '' ) {
	global $wp_version;

	if ( ! $version ) {
		return false;
	}

	$response = wp_remote_get(
		bp_beta_tester_get_version_dev_notes_url( $version ),
		array(
			'user-agent' => 'WordPress/' . $wp_version . '; ' . home_url( '/' ),
		)
	);

	return 200 === wp_remote_retrieve_response_code( $response );
}

/**
 * Add a Dashboard submenu.
 *
 * @since 1.0.0
 */
function bp_beta_tester_admin_menu() {
	$page = add_dashboard_page(
		__( 'BuddyPress Beta Tester', 'bp-beta-tester' ),
		__( 'Beta Test BuddyPress', 'bp-beta-tester' ),
		'manage_options',
		'bp-beta-tester',
		'bp_beta_tester_admin_page'
	);

	add_action( 'load-' . $page, 'bp_beta_tester_admin_load' );
}

/**
 * Add a link to the BP Beta Tester Admin page.
 *
 * @since 1.0.0
 *
 * @param array  $links        Links array in which we would append our link.
 * @param string $plugin_file  Current plugin basename.
 * @return array               Processed links.
 */
function bp_beta_tester_plugin_action_links( $links = array(), $plugin_file = '' ) {
	// Only add the link for BP Beta Tester plugin.
	if ( 'bp-beta-tester/class-bp-beta-tester.php' === $plugin_file ) {
		$links['beta-test'] = '<a href="' . esc_url( add_query_arg( array( 'page' => 'bp-beta-tester' ), self_admin_url( 'index.php' ) ) ) . '">' . esc_html__( 'Beta Test BuddyPress', 'bp-beta-tester' ) . '</a>';
	}

	return $links;
}

if ( is_multisite() ) {
	add_action( 'network_admin_menu', 'bp_beta_tester_admin_menu' );
	add_filter( 'network_admin_plugin_action_links', 'bp_beta_tester_plugin_action_links', 10, 2 );
} else {
	add_action( 'admin_menu', 'bp_beta_tester_admin_menu' );
	add_filter( 'plugin_action_links', 'bp_beta_tester_plugin_action_links', 10, 2 );
}

/**
 * Plugin's version updater.
 *
 * @since 1.2.0
 */
function bp_beta_tester_version_updater() {
	$version    = bp_beta_tester()->version;
	$db_version = get_network_option( 0, '_bp_beta_tester_version', 0 );

	if ( ! version_compare( $db_version, $version, '<' ) ) {
		return;
	}

	if ( ! $db_version ) {
		// Make sure the pre release transient is deleted.
		bp_beta_tester_delete_pre_release_transient();
	}

	update_network_option( 0, '_bp_beta_tester_version', $version );
}
add_action( 'admin_init', 'bp_beta_tester_version_updater', 1000 );
