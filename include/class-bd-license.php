<?php
/**
 * Addon license related functions
 *
 * @package    Bulk_Delete
 * @subpackage addon
 * @author     Sudar
 * @since      5.0
 */
class BD_License {
    /**
     * Output addon page content
     *
     * @since 5.0
     * @static
     */
    public static function display_addon_page() {
        if( !class_exists( 'WP_List_Table' ) ){
            require_once( ABSPATH . WPINC . '/class-wp-list-table.php' );
        }

        if ( !class_exists( 'License_List_Table' ) ) {
            require_once Bulk_Delete::$PLUGIN_DIR . '/include/class-license-list-table.php';
        }

        $license_list_table = new License_List_Table();
        $license_list_table->prepare_items();
?>
        <div class="wrap">
            <h2><?php _e( 'Addon Licenses', 'bulk-delete' );?></h2>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
<?php
            $license_list_table->display();
            do_action( 'bd_license_form' );
            BD_License::display_available_addon_list();
?>
            </form>
        </div>
<?php
        /**
         * Runs just before displaying the footer text in the "Addon" admin page.
         *
         * This action is primarily for adding extra content in the footer of "Addon" admin page.
         *
         * @since 5.0
         */
        do_action( 'bd_admin_footer_addon_page' );
    }

    /**
     * Display License form
     *
     * @since 5.0
     * @static
     */
    public static function display_activate_license_form() {
        $bd = BULK_DELETE();
        if ( isset( $bd->display_activate_license_form ) && TRUE == $bd->display_activate_license_form ) {
            // This prints out all hidden setting fields
            settings_fields( $bd::SETTING_OPTION_GROUP );
            do_settings_sections( $bd::ADDON_PAGE_SLUG );
            submit_button( __( 'Activate License', 'bulk-delete' ) );
        }
    }

    /**
     * Check if an addon has a valid license or not
     *
     * @since  5.0
     * @static
     * @param  string $addon_name Addon Name
     * @param  string $addon_code Addon short Name
     * @return bool   True if addon has a valid license, False otherwise
     */
    public static function has_valid_license( $addon_name, $addon_code ) {
        $bd = BULK_DELETE();
        $key = Bulk_Delete::LICENSE_CACHE_KEY_PREFIX . $addon_code;
        $license_data = get_option( $key, FALSE );

        if ( ! $license_data ) {
            // if data about license is not present, then fetch it.
            // ideally this should not happen
            $licenses = get_option( $bd::SETTING_OPTION_NAME );
            if ( is_array( $licenses ) && key_exists( $addon_code, $licenses ) ) {
                $license_data = BD_EDD_API_Wrapper::check_license( $addon_name, $licenses[ $addon_code ] );
                update_option( $key, $license_data );
            }
        }

        // TODO Encapsulate below code into a separate function
        if ( $license_data && is_array( $license_data ) && key_exists( 'validity', $license_data ) ) {
            if ( 'valid' == $license_data['validity'] ) {
                if ( strtotime( 'now' ) < strtotime( $license_data['expires'] ) ) {
                    return TRUE;
                } else {
                    $license_data['validity'] = 'expired';
                    update_option( $key, $license_data );
                }
            }
        }

        return FALSE;
    }

    /**
     * Get the list of all licenses information to be displayed in the license page
     *
     * @since 5.0
     * @static
     * @return array $license_data License information
     */
    public static function get_licenses() {
        $bd = BULK_DELETE();
        $licenses = get_option( $bd::SETTING_OPTION_NAME );
        $license_data = array();

        if ( is_array( $licenses ) ) {
            foreach ( $licenses as $addon_code => $license ) {
                $license_data[ $addon_code ] = self::get_license( $addon_code, $license );
            }
        }

        return $license_data;
    }

    /**
     * Retrieve license information about an addon
     *
     * @since  5.0
     * @static
     * @param  string $addon_code   Addon short name
     * @return object $license_data License information
     */
    public static function get_license( $addon_code ) {
        $bd = BULK_DELETE();
        $key = Bulk_Delete::LICENSE_CACHE_KEY_PREFIX . $addon_code;
        $license_data = get_option( $key, FALSE );

        if ( $license_data && is_array( $license_data ) && key_exists( 'validity', $license_data ) ) {
            if ( 'valid' == $license_data['validity'] ) {
                if ( strtotime( 'now' ) < strtotime( $license_data['expires'] ) ) {
                    // valid license
                } else {
                    $license_data['validity'] = 'expired';
                    update_option( $key, $license_data );
                }
            }
        }

        return $license_data;
    }

    /**
     * Get license code of an addon
     *
     * @since 5.0
     * @static
     * @param string $addon_code Addon code
     * @return bool|string License code of the addon, False otherwise
     */
    public static function get_license_code( $addon_code ) {
        $bd = BULK_DELETE();
        $licenses = get_option( $bd::SETTING_OPTION_NAME );

        if ( is_array($licenses ) && key_exists( $addon_code, $licenses ) ) {
            return $licenses[ $addon_code ];
        }
        else {
            return FALSE;
        }
    }

    /**
     * Deactivate license
     *
     * @since 5.0
     * @static
     */
    public static function deactivate_license() {
        if ( check_admin_referer( 'bd-deactivate-license', 'bd-deactivate-license-nonce' ) ) {
            $bd           = BULK_DELETE();
            $msg          = array( 'msg' => '', 'type' => 'error' );
            $addon_code   = $_GET['addon-code'];
            $license_data = self::get_license( $addon_code );

            $license      = $license_data['license'];
            $addon_name   = $license_data['addon-name'];

            $deactivated  = BD_EDD_API_Wrapper::deactivate_license( $addon_name, $license );

            if ( $deactivated ) {
                self::delete_license_from_cache( $addon_code );
                $msg['msg']  = sprintf( __( 'The license key for "%s" addon was successfully deactivated', 'bulk-delete' ), $addon_name );
                $msg['type'] = 'updated';

            } else {
                self::validate_license( $addon_code, $addon_name );
                $msg['msg'] = sprintf( __( 'There was some problem while trying to deactivate license key for "%s" addon. Kindly try again', 'bulk-delete' ), $addon_name );
            }

            add_settings_error(
                $bd::ADDON_PAGE_SLUG,
                'license-deactivation',
                $msg['msg'],
                $msg['type']
            );
        }
    }

    /**
     * Delete license
     *
     * @since 5.0
     * @static
     */
    public static function delete_license() {
        if ( check_admin_referer( 'bd-deactivate-license', 'bd-deactivate-license-nonce' ) ) {
            $bd           = BULK_DELETE();
            $msg          = array( 'msg' => '', 'type' => 'updated' );
            $addon_code   = $_GET['addon-code'];

            self::delete_license_from_cache( $addon_code );

            $msg['msg']  = __( 'The license key was successfully deleted', 'bulk-delete' );

            add_settings_error(
                $bd::ADDON_PAGE_SLUG,
                'license-deleted',
                $msg['msg'],
                $msg['type']
            );
        }
    }

    /**
     * Delete license information from cache
     *
     * @since 5.0
     * @static
     * @param string $addon_code Addon code
     */
    private static function delete_license_from_cache( $addon_code ) {
        $key = Bulk_Delete::LICENSE_CACHE_KEY_PREFIX . $addon_code;
        delete_option( $key );

        $licenses = get_option( Bulk_Delete::SETTING_OPTION_NAME );

        if ( is_array( $licenses ) && key_exists( $addon_code, $licenses ) ) {
            unset( $licenses[ $addon_code ] );
        }
        update_option( Bulk_Delete::SETTING_OPTION_NAME, $licenses );
    }

    /*
     * Activate license
     *
     * @since  5.0
     * @static
     * @param  string $addon_name Addon name
     * @param  string $addon_code Addon code
     * @param  string $license    License code
     * @return bool   $valid      True if valid, False otherwise
     */
    public static function activate_license( $addon_name, $addon_code, $license ) {
        $license_data = BD_EDD_API_Wrapper::activate_license( $addon_name, $license );
        $bd           = BULK_DELETE();
        $valid        = FALSE;
        $msg          = array(
            'msg'  => sprintf( __( 'There was some problem in contacting our store to activate the license key for "%s" addon', 'bulk-delete' ), $addon_name ),
            'type' => 'error'
        );

        if ( $license_data && is_array( $license_data ) && key_exists( 'validity', $license_data ) ) {
            if ( 'valid' == $license_data['validity'] ) {
                $key = Bulk_Delete::LICENSE_CACHE_KEY_PREFIX . $addon_code;
                $license_data['addon-code'] = $addon_code;
                update_option( $key, $license_data );

                $msg['msg']  = sprintf( __( 'The license key for "%s" addon was successfully activated. The addon will get updates automatically till the license key is valid.', 'bulk-delete' ), $addon_name );
                $msg['type'] = 'updated';
                $valid = TRUE;
            } else {
                if ( key_exists( 'error', $license_data ) ) {
                    switch( $license_data['error'] ) {

                        case 'no_activations_left':
                            $msg['msg'] = sprintf( __( 'The license key for "%s" addon doesn\'t have any more activations left. Kindly buy a new license.', 'bulk-delete' ), $addon_name );
                            break;

                        case 'revoked':
                            $msg['msg'] = sprintf( __( 'The license key for "%s" addon is revoked. Kindly buy a new license.', 'bulk-delete' ), $addon_name );
                            break;

                        case 'expired':
                            $msg['msg'] = sprintf( __( 'The license key for "%s" addon has expired. Kindly buy a new license.', 'bulk-delete' ), $addon_name );
                            break;

                        default:
                            $msg['msg'] = sprintf( __( 'The license key for "%s" addon is invalid', 'bulk-delete' ), $addon_name );
                            break;
                    }
                }
            }
        }

        add_settings_error(
            $bd::ADDON_PAGE_SLUG,
            'license-activation',
            $msg['msg'],
            $msg['type']
        );

        if ( !$valid && isset( $key ) ) {
            delete_option( $key );
        }
        return $valid;
    }

    /**
     * Validate the license for the given addon
     *
     * @since 5.0
     * @static
     * @param  string $addon_name Addon name
     * @param  string $addon_code Addon code
     */
    public static function validate_license( $addon_code, $addon_name ) {
        $key = Bulk_Delete::LICENSE_CACHE_KEY_PREFIX . $addon_code;

        $licenses = get_option( Bulk_Delete::SETTING_OPTION_NAME );
        if ( is_array( $licenses ) && key_exists( $addon_code, $licenses ) ) {
            $license_data = BD_EDD_API_Wrapper::check_license( $addon_name, $licenses[ $addon_code ] );
            if ( $license_data ) {
                $license_data['addon-code'] = $addon_code;
                $license_data['addon-name'] = $license_data['item_name'];
                update_option( $key, $license_data );
            } else {
                delete_option( $key );
            }
        }

        if ( $license_data && is_array( $license_data ) && key_exists( 'validity', $license_data ) ) {
            if ( 'valid' == $license_data['validity'] ) {
                if ( strtotime( 'now' ) > strtotime( $license_data['expires'] ) ) {
                    $license_data['validity'] = 'expired';
                    update_option( $key, $license_data );
                }
            }
        }
    }

    /**
     * Display information about all available addons
     *
     * @since 5.0
     * @static
     */
    public static function display_available_addon_list() {

        echo '<p>';
        _e('The following are the list of pro addons that are currently available for purchase.', 'bulk-delete');
        echo '</p>';

        echo '<ul style="list-style:disc; padding-left:35px">';

        echo '<li>';
        echo '<strong>', __('Delete posts by custom field', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to delete posts based on custom fields', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-by-custom-field">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-by-custom-field">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __( 'Delete posts by duplicate title', 'bulk-delete' ), '</strong>', ' - ';
        echo __( 'Adds the ability to delete posts based on duplicate title', 'bulk-delete' );
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-by-duplicate-title">', __( 'More Info', 'bulk-delete' ), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-by-duplicate-title">', __( 'Buy now', 'bulk-delete' ), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __( 'Delete posts by title', 'bulk-delete' ), '</strong>', ' - ';
        echo __( 'Adds the ability to delete posts based on title', 'bulk-delete' );
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-by-title">', __( 'More Info', 'bulk-delete' ), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-by-title">', __( 'Buy now', 'bulk-delete' ), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of Posts by Categories', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete of posts based on categories', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-categories">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-category-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of Posts by Tags', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete of posts based on tags', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-tags">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-tags-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of posts by Custom Taxonomy', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete of posts based on custom taxonomies', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-taxonomy">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-taxonomy-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of Posts by Custom Post Type', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete of posts based on custom post types', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-post-types">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-post-type-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of Pages', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete pages', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-pages">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-pages-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of Posts by Post Status', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete of posts based on post status like drafts, pending posts, scheduled posts etc.', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-post-status">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-post-status-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '<li>';
        echo '<strong>', __('Schedule auto delete of Users by User Role', 'bulk-delete'), '</strong>', ' - ';
        echo __('Adds the ability to schedule auto delete of users based on user role', 'bulk-delete');
        echo ' <a href = "http://sudarmuthu.com/wordpress/bulk-delete/pro-addons#bulk-delete-schedule-users-by-user-role">', __('More Info', 'bulk-delete'), '</a>.';
        echo ' <a href = "http://sudarmuthu.com/out/buy-bulk-delete-users-by-role-addon">', __('Buy now', 'bulk-delete'), '</a>';
        echo '</li>';

        echo '</ul>';
    }
}

// hooks
add_action( 'bd_license_form'       , array( 'BD_License' , 'display_activate_license_form' ), 100 );
add_action( 'bd_deactivate_license' , array( 'BD_License' , 'deactivate_license' ) );
add_action( 'bd_delete_license'     , array( 'BD_License' , 'delete_license' ) );
add_action( 'bd_validate_license'   , array( 'BD_License' , 'validate_license' ), 10, 2 );
?>
