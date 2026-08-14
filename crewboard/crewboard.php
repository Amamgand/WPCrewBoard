<?php
/**
 * Plugin Name: CrewBoard
 * Description: Einfaches Mitgliederportal und Dienstplanung für Events Manager.
 * Version: 0.2.0
 * Author: Bühne-Schlachthof Eisenach e. V.
 * Text Domain: crewboard
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CrewBoard {
    const VERSION = '0.3.3';
    const META_SERVICES = '_crewboard_services';
    const PAGE_OPTION = 'crewboard_portal_page_id';
    const META_TEAMS = '_crewboard_teams';
    const META_LEVEL = '_crewboard_level';
    const OPTION_SELF_ASSIGN  = 'crewboard_self_assign';
    const OPTION_CUSTOM_TEAMS  = 'crewboard_custom_teams';
    const META_ICS_TOKEN       = '_crewboard_ics_token';
    const OPTION_DEFAULT_SERVICES = 'crewboard_default_services';

    public static function init(): void {
        add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
        add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_crewboard_save_members', array( __CLASS__, 'save_members' ) );
        add_action( 'admin_post_crewboard_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'add_meta_boxes_event', array( __CLASS__, 'add_event_metabox' ) );
        add_action( 'save_post_event', array( __CLASS__, 'save_event_services' ), 20, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );
        add_shortcode( 'crewboard', array( __CLASS__, 'shortcode_portal' ) );
        add_action( 'admin_post_crewboard_claim_service', array( __CLASS__, 'claim_service' ) );
        add_action( 'admin_post_crewboard_save_event_services', array( __CLASS__, 'save_services_from_admin_page' ) );
        add_action( 'admin_post_crewboard_respond_service', array( __CLASS__, 'respond_service' ) );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
        add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'ics_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_ics_feed' ) );
        add_action( 'admin_post_crewboard_rotate_ics_token', array( __CLASS__, 'rotate_ics_token' ) );
    }

    public static function activate(): void {
        if ( false === get_option( self::OPTION_SELF_ASSIGN, false ) ) {
            add_option( self::OPTION_SELF_ASSIGN, '1' );
        }
        if ( false === get_option( self::OPTION_CUSTOM_TEAMS, false ) ) {
            add_option( self::OPTION_CUSTOM_TEAMS, array() );
        }
        if ( false === get_option( self::OPTION_DEFAULT_SERVICES, false ) ) {
            add_option( self::OPTION_DEFAULT_SERVICES, array( 'theke' => 2, 'einlass' => 2, 'einkauf' => 1 ) );
        }

        // Ensure the portal page exists (do not short-circuit before the rewrite flush).
        $page_id = (int) get_option( self::PAGE_OPTION );
        if ( ! $page_id || ! get_post( $page_id ) ) {
            $existing = get_posts(
                array(
                    'name'           => 'intern',
                    'post_type'      => 'page',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                )
            );
            if ( ! empty( $existing ) ) {
                update_option( self::PAGE_OPTION, (int) $existing[0] );
            } else {
                $new_id = wp_insert_post(
                    array(
                        'post_title'   => 'CrewBoard',
                        'post_name'    => 'intern',
                        'post_content' => '[crewboard]',
                        'post_status'  => 'publish',
                        'post_type'    => 'page',
                    ),
                    true
                );
                if ( ! is_wp_error( $new_id ) ) {
                    update_option( self::PAGE_OPTION, (int) $new_id );
                }
            }
        }

        // Always register the ICS rewrite rule and flush on every activation / re-activation.
        self::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function register_rewrite_rules(): void {
        add_rewrite_rule( '^crewboard/calendar\.ics$', 'index.php?crewboard_ics_feed=1', 'top' );
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain( 'crewboard', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public static function dependency_notice(): void {
        if ( current_user_can( 'activate_plugins' ) && ! post_type_exists( 'event' ) ) {
            echo '<div class="notice notice-warning"><p><strong>CrewBoard:</strong> Der Beitragstyp <code>event</code> wurde nicht gefunden. Bitte Events Manager aktivieren.</p></div>';
        }
    }

    public static function add_event_metabox( WP_Post $post ): void {
        add_meta_box(
            'crewboard-services',
            'CrewBoard – Dienste & Aufgaben',
            array( __CLASS__, 'render_event_metabox' ),
            'event',
            'normal',
            'high'
        );
    }

    public static function render_event_metabox( WP_Post $post ): void {
        wp_nonce_field( 'crewboard_save_services', 'crewboard_nonce' );
        $services = self::get_services( $post->ID );
        $users = get_users(
            array(
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => array( 'ID', 'display_name' ),
            )
        );
        $users = array_values( array_filter( $users, static fn( $user ): bool => self::user_is_eligible( (int) $user->ID, '' ) ) );
        ?>
        <p>Hier werden Dienste angelegt und Mitgliedern zugewiesen. Freie Plätze erscheinen später im Mitgliederportal.</p>
        <table class="widefat striped" id="crewboard-services-table">
            <thead>
                <tr>
                    <th>Dienst/Aufgabe</th>
                    <th>Beginn</th>
                    <th>Ende</th>
                    <th>Bedarf</th>
                    <th>Team</th>
                    <th>Zugewiesene Mitglieder</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ( empty( $services ) ) {
                $services[] = self::empty_service();
            }
            foreach ( $services as $index => $service ) {
                self::render_service_row( $index, $service, $users );
            }
            ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="crewboard-add-service">+ Dienst hinzufügen</button></p>
        <script type="text/template" id="crewboard-service-template">
            <?php self::render_service_row( '__INDEX__', self::empty_service(), $users ); ?>
        </script>
        <?php
    }

    private static function render_service_row( $index, array $service, array $users ): void {
        $assigned = array_map( 'intval', $service['assigned'] ?? array() );
        ?>
        <tr class="crewboard-service-row">
            <td>
                <input type="hidden" name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( $service['id'] ?? wp_generate_uuid4() ); ?>">
                <input type="text" name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( $service['title'] ?? '' ); ?>" placeholder="z. B. Theke" required>
            </td>
            <td><input type="datetime-local" name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][start]" value="<?php echo esc_attr( $service['start'] ?? '' ); ?>"></td>
            <td><input type="datetime-local" name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][end]" value="<?php echo esc_attr( $service['end'] ?? '' ); ?>"></td>
            <td><input type="number" min="1" max="50" name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][needed]" value="<?php echo esc_attr( (string) ( $service['needed'] ?? 1 ) ); ?>" class="small-text"></td>
            <td>
                <select name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][team]">
                    <option value="">Alle Teams</option>
                    <?php foreach ( self::teams() as $team_key => $team_label ) : ?>
                        <option value="<?php echo esc_attr( $team_key ); ?>" <?php selected( $service['team'] ?? '', $team_key ); ?>><?php echo esc_html( $team_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <div class="crewboard-member-checkboxes">
                    <?php
                    $responses    = is_array( $service['responses'] ?? null ) ? $service['responses'] : array();
                    $status_icons = array( 'accepted' => '✓', 'denied' => '✗', 'pending' => '–' );
                    foreach ( $users as $user ) :
                        $is_assigned = in_array( (int) $user->ID, $assigned, true );
                        $resp        = $is_assigned ? ( $responses[ (string) $user->ID ] ?? array( 'status' => 'pending', 'reason' => '' ) ) : null;
                        ?>
                        <label>
                            <input type="checkbox" name="crewboard_services[<?php echo esc_attr( (string) $index ); ?>][assigned][]" value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php checked( $is_assigned ); ?>>
                            <?php echo esc_html( $user->display_name ); ?>
                            <?php if ( $resp ) :
                                $reason_attr = ! empty( $resp['reason'] ) ? ' title="' . esc_attr( $resp['reason'] ) . '"' : ''; ?>
                                <span class="crewboard-admin-response crewboard-response-<?php echo esc_attr( $resp['status'] ); ?>"<?php echo $reason_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $status_icons[ $resp['status'] ] ?? '–' ); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </td>
            <td><button type="button" class="button-link-delete crewboard-remove-service">Entfernen</button></td>
        </tr>
        <?php
    }

    private static function empty_service(): array {
        return array(
            'id'       => wp_generate_uuid4(),
            'title'    => '',
            'start'    => '',
            'end'      => '',
            'needed'   => 1,
            'team'     => '',
            'assigned' => array(),
        );
    }

    private static function get_default_service_definitions(): array {
        $config = get_option( self::OPTION_DEFAULT_SERVICES, array( 'theke' => 2, 'einlass' => 2, 'einkauf' => 1 ) );
        if ( ! is_array( $config ) || empty( $config ) ) {
            return array();
        }
        $all_teams = self::teams();
        $services  = array();
        foreach ( $config as $team_key => $needed ) {
            $team_key = sanitize_key( (string) $team_key );
            if ( ! isset( $all_teams[ $team_key ] ) ) {
                continue;
            }
            $services[] = array(
                'id'        => wp_generate_uuid4(),
                'title'     => $all_teams[ $team_key ],
                'start'     => '',
                'end'       => '',
                'needed'    => max( 1, (int) $needed ),
                'team'      => $team_key,
                'assigned'  => array(),
                'responses' => array(),
            );
        }
        return $services;
    }

    public static function save_event_services( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['crewboard_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['crewboard_nonce'] ) ), 'crewboard_save_services' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        self::persist_services_from_request( $post_id );
    }

    private static function sanitize_datetime_local( string $value ): string {
        $value = sanitize_text_field( $value );
        return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
    }

    public static function admin_assets( string $hook ): void {
        $screen = get_current_screen();
        $is_event_editor = $screen && 'event' === $screen->post_type;
        $is_crewboard_page = str_contains( $hook, 'crewboard' );
        if ( ! $is_event_editor && ! $is_crewboard_page ) {
            return;
        }
        wp_enqueue_script( 'crewboard-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), self::VERSION, true );
        wp_enqueue_style( 'crewboard-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
    }

    public static function frontend_assets(): void {
        if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'crewboard' ) ) {
            wp_enqueue_style( 'crewboard', plugins_url( 'assets/crewboard.css', __FILE__ ), array(), self::VERSION );
            wp_enqueue_script( 'crewboard', plugins_url( 'assets/crewboard.js', __FILE__ ), array(), self::VERSION, true );
        }
    }

    public static function shortcode_portal(): string {
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return '<div class="crewboard-login"><h2>CrewBoard</h2><p>Bitte melde dich an, um deine Termine und Dienste zu sehen.</p><p><a class="crewboard-button" href="' . esc_url( $login_url ) . '">Anmelden</a></p></div>';
        }

        $user = wp_get_current_user();
        $month = isset( $_GET['cb_month'] ) ? sanitize_text_field( wp_unslash( $_GET['cb_month'] ) ) : wp_date( 'Y-m' );
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            $month = wp_date( 'Y-m' );
        }

        ob_start();
        ?>
        <div class="crewboard-wrap">
            <header class="crewboard-hero">
                <div>
                    <span class="crewboard-eyebrow">CrewBoard</span>
                    <h1>Hallo <?php echo esc_html( $user->display_name ); ?> 👋</h1>
                    <p>Hier findest du deine nächsten Dienste und alle geplanten Veranstaltungen.</p>
                </div>
                <a class="crewboard-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Abmelden</a>
            </header>

            <?php
            $cb_param = isset( $_GET['crewboard'] ) ? sanitize_key( wp_unslash( $_GET['crewboard'] ) ) : '';
            if ( 'claimed' === $cb_param ) : ?>
                <div class="crewboard-notice crewboard-notice-success">✓ Du wurdest für den Dienst eingetragen!</div>
            <?php elseif ( 'responded_accepted' === $cb_param ) : ?>
                <div class="crewboard-notice crewboard-notice-success">✓ Danke! Deine Zusage wurde gespeichert.</div>
            <?php elseif ( 'responded_denied' === $cb_param ) : ?>
                <div class="crewboard-notice crewboard-notice-warning">Deine Ablehnung wurde gespeichert. Die Koordination wurde informiert.</div>
            <?php elseif ( 'ics_rotated' === $cb_param ) : ?>
                <div class="crewboard-notice crewboard-notice-success">✓ Dein Kalender-Link wurde neu erstellt. Der alte Link ist sofort ungültig.</div>
            <?php endif; ?>
            <?php echo self::render_my_services( (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::render_open_services( (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::render_calendar( $month, (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::render_ics_subscription( (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_my_services( int $user_id ): string {
        $items = self::collect_services( $user_id, 'assigned' );
        ob_start();
        ?>
        <section class="crewboard-section">
            <div class="crewboard-section-title"><h2>Meine nächsten Dienste</h2><span><?php echo esc_html( (string) count( $items ) ); ?></span></div>
            <?php if ( empty( $items ) ) : ?>
                <div class="crewboard-empty">Du bist aktuell für keinen kommenden Dienst eingetragen.</div>
            <?php else : ?>
                <div class="crewboard-cards">
                    <?php foreach ( array_slice( $items, 0, 8 ) as $item ) : ?>
                        <?php echo self::service_card( $item, false, $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_open_services( int $user_id ): string {
        $items = self::collect_services( $user_id, 'open' );
        ob_start();
        ?>
        <section class="crewboard-section">
            <div class="crewboard-section-title"><h2>Offene Dienste</h2><span><?php echo esc_html( (string) count( $items ) ); ?></span></div>
            <?php if ( empty( $items ) ) : ?>
                <div class="crewboard-empty">Aktuell sind alle eingetragenen Dienste vollständig besetzt.</div>
            <?php else : ?>
                <div class="crewboard-cards">
                    <?php foreach ( array_slice( $items, 0, 8 ) as $item ) : ?>
                        <?php echo self::service_card( $item, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function service_card( array $item, bool $claimable, int $user_id = 0 ): string {
        $service        = $item['service'];
        $event          = $item['event'];
        $when           = self::format_service_time( $service, $event );
        $assigned_count = count( $service['assigned'] ?? array() );
        $needed         = max( 1, (int) ( $service['needed'] ?? 1 ) );

        // Response state for the viewing member (only set when called for assigned services).
        $responses = is_array( $service['responses'] ?? null ) ? $service['responses'] : array();
        $response  = $user_id > 0 ? ( $responses[ (string) $user_id ] ?? array( 'status' => 'pending', 'reason' => '' ) ) : array();
        $status    = $response['status'] ?? '';

        $badge_map = array(
            'accepted' => array( 'label' => '✓ Zugesagt',   'cls' => 'accepted' ),
            'denied'   => array( 'label' => '✗ Abgelehnt',  'cls' => 'denied' ),
            'pending'  => array( 'label' => '– Ausstehend', 'cls' => 'pending' ),
        );
        $badge = isset( $badge_map[ $status ] ) ? $badge_map[ $status ] : null;

        ob_start();
        ?>
        <article class="crewboard-card<?php echo 'denied' === $status ? ' crewboard-card-denied' : ''; ?>">
            <div class="crewboard-card-top">
                <span class="crewboard-card-date"><?php echo esc_html( self::event_date_label( $event ) ); ?></span>
                <?php if ( $badge ) : ?>
                    <span class="crewboard-response-badge crewboard-response-<?php echo esc_attr( $badge['cls'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
                <?php endif; ?>
            </div>
            <div class="crewboard-card-event-title"><?php echo esc_html( get_the_title( $event ) ); ?></div>
            <div class="crewboard-card-meta">
                <strong><?php echo esc_html( $service['title'] ); ?></strong><?php echo esc_html( ' · ' . $when . ' · ' . sprintf( '%d/%d', $assigned_count, $needed ) ); ?>
            </div>
            <?php if ( 'denied' === $status && ! empty( $response['reason'] ) ) : ?>
                <p class="crewboard-response-reason"><?php echo esc_html( $response['reason'] ); ?></p>
            <?php endif; ?>

            <?php if ( '' !== $status ) : ?>
                <div class="crewboard-respond-inline">
                    <?php if ( 'accepted' !== $status ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action"          value="crewboard_respond_service">
                            <input type="hidden" name="event_id"        value="<?php echo esc_attr( (string) $event->ID ); ?>">
                            <input type="hidden" name="service_id"      value="<?php echo esc_attr( $service['id'] ); ?>">
                            <input type="hidden" name="response_status" value="accepted">
                            <?php wp_nonce_field( 'crewboard_respond_' . $event->ID . '_' . $service['id'], 'crewboard_respond_nonce' ); ?>
                            <button class="crewboard-button crewboard-button-accept" type="submit">✓ Zusagen</button>
                        </form>
                    <?php endif; ?>
                    <?php if ( 'denied' !== $status ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crewboard-deny-inline">
                            <input type="hidden" name="action"          value="crewboard_respond_service">
                            <input type="hidden" name="event_id"        value="<?php echo esc_attr( (string) $event->ID ); ?>">
                            <input type="hidden" name="service_id"      value="<?php echo esc_attr( $service['id'] ); ?>">
                            <input type="hidden" name="response_status" value="denied">
                            <?php wp_nonce_field( 'crewboard_respond_' . $event->ID . '_' . $service['id'], 'crewboard_respond_nonce' ); ?>
                            <input type="text" name="response_reason" class="crewboard-deny-reason-input" placeholder="Ablehnungsgrund (optional)">
                            <button class="crewboard-button crewboard-button-deny" type="submit">✗ Absage</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $claimable && '1' === get_option( self::OPTION_SELF_ASSIGN, '1' ) ) : ?>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action"     value="crewboard_claim_service">
                    <input type="hidden" name="event_id"   value="<?php echo esc_attr( (string) $event->ID ); ?>">
                    <input type="hidden" name="service_id" value="<?php echo esc_attr( $service['id'] ); ?>">
                    <?php wp_nonce_field( 'crewboard_claim_' . $event->ID . '_' . $service['id'], 'crewboard_claim_nonce' ); ?>
                    <button class="crewboard-button" type="submit">Ich übernehme das</button>
                </form>
            <?php endif; ?>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_calendar( string $month, int $user_id ): string {
        $tz    = wp_timezone();
        $first = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $tz );
        if ( ! $first ) {
            return '';
        }

        $last       = $first->modify( 'last day of this month' );
        $prev       = $first->modify( '-1 month' )->format( 'Y-m' );
        $next       = $first->modify( '+1 month' )->format( 'Y-m' );
        $events     = self::get_events_between( $first, $last );
        $portal_url = get_permalink();
        $today      = wp_date( 'Y-m-d', null, $tz );

        // Build per-date payload for the grid and server-side detail panels.
        $day_data     = array();
        $event_panels = array();
        $self_assign  = '1' === get_option( self::OPTION_SELF_ASSIGN, '1' );

        foreach ( $events as $event ) {
            $date = self::event_start_date( $event );
            if ( ! $date ) {
                continue;
            }
            $key      = $date->format( 'Y-m-d' );
            $services = self::get_services( $event->ID );

            $mine = array_values( array_filter( $services,
                fn( array $s ): bool => in_array( $user_id, array_map( 'intval', $s['assigned'] ?? array() ), true )
            ) );

            $open = $self_assign ? array_values( array_filter( $services,
                fn( array $s ): bool =>
                    self::user_is_eligible( $user_id, (string) ( $s['team'] ?? '' ) ) &&
                    ! in_array( $user_id, array_map( 'intval', $s['assigned'] ?? array() ), true ) &&
                    count( $s['assigned'] ?? array() ) < max( 1, (int) ( $s['needed'] ?? 1 ) )
            ) ) : array();

            // Compute dominant response status for this user on this event.
            $my_status = '';
            foreach ( $mine as $ms ) {
                $svc_resp   = is_array( $ms['responses'] ?? null ) ? $ms['responses'] : array();
                $svc_status = ( $svc_resp[ (string) $user_id ] ?? array() )['status'] ?? 'pending';
                if ( 'pending' === $svc_status ) { $my_status = 'pending'; break; }
                if ( 'denied' === $svc_status && 'pending' !== $my_status ) { $my_status = 'denied'; }
                if ( 'accepted' === $svc_status && '' === $my_status ) { $my_status = 'accepted'; }
            }

            $day_data[ $key ][] = array(
                'event_id'    => $event->ID,
                'title'       => get_the_title( $event ),
                'has_my_task' => ! empty( $mine ),
                'my_services' => array_column( $mine, 'title' ),
                'my_status'   => $my_status,
            );

            $event_panels[] = array( 'event' => $event, 'mine' => $mine, 'open' => $open );
        }

        $start_dow     = (int) $first->format( 'N' ); // 1 = Mon … 7 = Sun
        $days_in_month = (int) $last->format( 'j' );

        ob_start();
        ?>
        <section class="crewboard-section crewboard-calendar-section">
            <div class="crewboard-calendar-head">
                <a href="<?php echo esc_url( add_query_arg( 'cb_month', $prev, $portal_url ) ); ?>" aria-label="Vorheriger Monat">‹</a>
                <h2><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp(), $tz ) ); ?></h2>
                <a href="<?php echo esc_url( add_query_arg( 'cb_month', $next, $portal_url ) ); ?>" aria-label="Nächster Monat">›</a>
            </div>

            <div class="crewboard-cal-grid" id="crewboard-cal-grid">
                <?php foreach ( array( 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' ) as $wd ) : ?>
                    <div class="crewboard-cal-wday"><?php echo esc_html( $wd ); ?></div>
                <?php endforeach; ?>

                <?php for ( $i = 1; $i < $start_dow; $i++ ) : ?>
                    <div class="crewboard-cal-cell crewboard-cal-empty"></div>
                <?php endfor; ?>

                <?php for ( $d = 1; $d <= $days_in_month; $d++ ) : ?>
                    <?php
                    $key        = sprintf( '%s-%02d', $month, $d );
                    $evts       = $day_data[ $key ] ?? array();
                    $has_evt    = ! empty( $evts );
                    $has_mine   = $has_evt && (bool) array_filter( $evts, fn( array $e ) => $e['has_my_task'] );
                    $is_today   = $key === $today;
                    // Dominant response status determines cell colour
                    $day_status = '';
                    foreach ( $evts as $e ) {
                        $s = $e['my_status'] ?? '';
                        if ( 'pending' === $s ) { $day_status = 'pending'; break; }
                        if ( 'denied' === $s && 'accepted' !== $day_status ) { $day_status = 'denied'; }
                        if ( 'accepted' === $s && '' === $day_status ) { $day_status = 'accepted'; }
                    }
                    $classes = 'crewboard-cal-cell';
                    if ( $has_evt )    { $classes .= ' has-event'; }
                    if ( $has_mine )   { $classes .= ' has-mine'; }
                    if ( $day_status ) { $classes .= ' mine-' . $day_status; }
                    if ( $is_today )   { $classes .= ' is-today'; }
                    ?>
                    <div class="<?php echo esc_attr( $classes ); ?>"
                        <?php if ( $has_evt ) : ?>data-events="<?php echo esc_attr( (string) wp_json_encode( $evts ) ); ?>"<?php endif; ?>>
                        <span class="crewboard-cal-num"><?php echo esc_html( (string) $d ); ?></span>
                    </div>
                <?php endfor; ?>
            </div>

        <?php // Server-rendered event detail panels (contain nonce-protected forms). ?>
        <div id="crewboard-evt-panels" hidden>
            <?php
            $badge_map = array(
                'accepted' => array( 'label' => '✓ Zugesagt',   'cls' => 'accepted' ),
                'denied'   => array( 'label' => '✗ Abgelehnt',  'cls' => 'denied' ),
                'pending'  => array( 'label' => '– Ausstehend', 'cls' => 'pending' ),
            );
            foreach ( $event_panels as $pd ) :
                $pevt = $pd['event'];
            ?>
            <div class="crewboard-evt-panel" id="crewboard-evt-<?php echo esc_attr( (string) $pevt->ID ); ?>" hidden>
                <div class="crewboard-evt-panel-header">
                    <span class="crewboard-evt-panel-date"><?php echo esc_html( self::event_date_label( $pevt ) ); ?></span>
                    <strong class="crewboard-evt-panel-title"><?php echo esc_html( get_the_title( $pevt ) ); ?></strong>
                </div>

                <?php if ( ! empty( $pd['mine'] ) ) : ?>
                    <p class="crewboard-evt-sublabel">Deine Einteilung</p>
                    <?php foreach ( $pd['mine'] as $svc ) :
                        $svc_responses = is_array( $svc['responses'] ?? null ) ? $svc['responses'] : array();
                        $resp          = $svc_responses[ (string) $user_id ] ?? array( 'status' => 'pending', 'reason' => '' );
                        $resp_status   = $resp['status'] ?? 'pending';
                        $badge         = $badge_map[ $resp_status ] ?? null;
                    ?>
                    <div class="crewboard-evt-task">
                        <div class="crewboard-evt-task-info">
                            <span class="crewboard-evt-task-title"><?php echo esc_html( $svc['title'] ); ?></span>
                            <?php if ( ! empty( $svc['start'] ) ) : ?>
                                <span class="crewboard-evt-task-time"><?php echo esc_html( self::format_service_time( $svc, $pevt ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( $badge ) : ?>
                                <span class="crewboard-response-badge crewboard-response-<?php echo esc_attr( $badge['cls'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( 'denied' === $resp_status && ! empty( $resp['reason'] ) ) : ?>
                                <span class="crewboard-response-reason"><?php echo esc_html( $resp['reason'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="crewboard-respond-inline">
                            <?php if ( 'accepted' !== $resp_status ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action"          value="crewboard_respond_service">
                                    <input type="hidden" name="event_id"        value="<?php echo esc_attr( (string) $pevt->ID ); ?>">
                                    <input type="hidden" name="service_id"      value="<?php echo esc_attr( $svc['id'] ); ?>">
                                    <input type="hidden" name="response_status" value="accepted">
                                    <?php wp_nonce_field( 'crewboard_respond_' . $pevt->ID . '_' . $svc['id'], 'crewboard_respond_nonce' ); ?>
                                    <button class="crewboard-button crewboard-button-accept" type="submit">✓ Zusagen</button>
                                </form>
                            <?php endif; ?>
                            <?php if ( 'denied' !== $resp_status ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crewboard-deny-inline">
                                    <input type="hidden" name="action"          value="crewboard_respond_service">
                                    <input type="hidden" name="event_id"        value="<?php echo esc_attr( (string) $pevt->ID ); ?>">
                                    <input type="hidden" name="service_id"      value="<?php echo esc_attr( $svc['id'] ); ?>">
                                    <input type="hidden" name="response_status" value="denied">
                                    <?php wp_nonce_field( 'crewboard_respond_' . $pevt->ID . '_' . $svc['id'], 'crewboard_respond_nonce' ); ?>
                                    <input type="text" name="response_reason" class="crewboard-deny-reason-input" placeholder="Ablehnungsgrund (optional)">
                                    <button class="crewboard-button crewboard-button-deny" type="submit">✗ Absage</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ( ! empty( $pd['open'] ) ) : ?>
                    <p class="crewboard-evt-sublabel">Freie Dienste</p>
                    <?php foreach ( $pd['open'] as $svc ) :
                        $spots = max( 1, (int) ( $svc['needed'] ?? 1 ) ) - count( $svc['assigned'] ?? array() );
                    ?>
                    <div class="crewboard-evt-task crewboard-evt-task-open">
                        <div class="crewboard-evt-task-info">
                            <span class="crewboard-evt-task-title"><?php echo esc_html( $svc['title'] ); ?></span>
                            <?php if ( ! empty( $svc['start'] ) ) : ?>
                                <span class="crewboard-evt-task-time"><?php echo esc_html( self::format_service_time( $svc, $pevt ) ); ?></span>
                            <?php endif; ?>
                            <span class="crewboard-evt-task-spots"><?php echo esc_html( (string) $spots . ( 1 === $spots ? ' Platz frei' : ' Plätze frei' ) ); ?></span>
                        </div>
                        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                            <input type="hidden" name="action"     value="crewboard_claim_service">
                            <input type="hidden" name="event_id"   value="<?php echo esc_attr( (string) $pevt->ID ); ?>">
                            <input type="hidden" name="service_id" value="<?php echo esc_attr( $svc['id'] ); ?>">
                            <?php wp_nonce_field( 'crewboard_claim_' . $pevt->ID . '_' . $svc['id'], 'crewboard_claim_nonce' ); ?>
                            <button class="crewboard-button" type="submit">Ich mache das</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ( empty( $pd['mine'] ) && empty( $pd['open'] ) ) : ?>
                    <p class="crewboard-evt-no-tasks">Für diese Veranstaltung gibt es keine Dienste für dich.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function collect_services( int $user_id, string $mode ): array {
        $start = new DateTimeImmutable( 'today', wp_timezone() );
        $end = $start->modify( '+12 months' );
        $events = self::get_events_between( $start, $end );
        $items = array();
        foreach ( $events as $event ) {
            foreach ( self::get_services( $event->ID ) as $service ) {
                $assigned = array_map( 'intval', $service['assigned'] ?? array() );
                $needed = max( 1, (int) ( $service['needed'] ?? 1 ) );
                if ( 'assigned' === $mode && in_array( $user_id, $assigned, true ) ) {
                    $items[] = array( 'event' => $event, 'service' => $service );
                }
                if ( 'open' === $mode && self::user_is_eligible( $user_id, (string) ( $service['team'] ?? '' ) ) && ! in_array( $user_id, $assigned, true ) && count( $assigned ) < $needed ) {
                    $items[] = array( 'event' => $event, 'service' => $service );
                }
            }
        }
        usort(
            $items,
            static function ( array $a, array $b ): int {
                return strcmp( self::service_sort_key( $a['service'], $a['event'] ), self::service_sort_key( $b['service'], $b['event'] ) );
            }
        );
        return $items;
    }

    private static function service_sort_key( array $service, WP_Post $event ): string {
        return ! empty( $service['start'] ) ? $service['start'] : ( self::event_start_date( $event )?->format( 'Y-m-d\TH:i' ) ?? '9999-12-31T23:59' );
    }

    public static function claim_service(): void {
        if ( ! is_user_logged_in() ) {
            auth_redirect();
        }
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        $service_id = isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '';
        $nonce = isset( $_POST['crewboard_claim_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['crewboard_claim_nonce'] ) ) : '';
        if ( ! $event_id || ! $service_id || ! wp_verify_nonce( $nonce, 'crewboard_claim_' . $event_id . '_' . $service_id ) ) {
            wp_die( 'Ungültige Anfrage.', 'CrewBoard', array( 'response' => 403 ) );
        }

        if ( '1' !== get_option( self::OPTION_SELF_ASSIGN, '1' ) ) {
            wp_die( 'Die Selbstzuweisung ist deaktiviert.', 'CrewBoard', array( 'response' => 403 ) );
        }

        $services = self::get_services( $event_id );
        $user_id = get_current_user_id();
        foreach ( $services as &$service ) {
            if ( $service_id !== ( $service['id'] ?? '' ) ) {
                continue;
            }
            if ( ! self::user_is_eligible( $user_id, (string) ( $service['team'] ?? '' ) ) ) {
                wp_die( 'Du bist für diesen Team-Dienst nicht freigeschaltet.', 'CrewBoard', array( 'response' => 403 ) );
            }
            $assigned = array_values( array_unique( array_map( 'intval', $service['assigned'] ?? array() ) ) );
            $needed = max( 1, (int) ( $service['needed'] ?? 1 ) );
            if ( ! in_array( $user_id, $assigned, true ) && count( $assigned ) < $needed ) {
                $assigned[] = $user_id;
                $service['assigned'] = $assigned;
            }
            break;
        }
        unset( $service );
        update_post_meta( $event_id, self::META_SERVICES, $services );
        wp_safe_redirect( add_query_arg( 'crewboard', 'claimed', self::portal_url() ) );
        exit;
    }

    public static function respond_service(): void {
        if ( ! is_user_logged_in() ) {
            auth_redirect();
        }
        $event_id   = isset( $_POST['event_id'] )               ? absint( $_POST['event_id'] )                                       : 0;
        $service_id = isset( $_POST['service_id'] )             ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) )          : '';
        $status     = isset( $_POST['response_status'] )        ? sanitize_key( wp_unslash( $_POST['response_status'] ) )            : '';
        $reason     = isset( $_POST['response_reason'] )        ? sanitize_textarea_field( wp_unslash( $_POST['response_reason'] ) ) : '';
        $nonce      = isset( $_POST['crewboard_respond_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['crewboard_respond_nonce'] ) ) : '';

        if ( ! $event_id || ! $service_id || ! in_array( $status, array( 'accepted', 'denied' ), true ) ) {
            wp_die( 'Ungültige Anfrage.', 'CrewBoard', array( 'response' => 400 ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'crewboard_respond_' . $event_id . '_' . $service_id ) ) {
            wp_die( 'Sicherheitsüberprüfung fehlgeschlagen.', 'CrewBoard', array( 'response' => 403 ) );
        }

        $user_id  = get_current_user_id();
        $services = self::get_services( $event_id );
        $found    = false;

        foreach ( $services as &$service ) {
            if ( ( $service['id'] ?? '' ) !== $service_id ) {
                continue;
            }
            $assigned = array_map( 'intval', $service['assigned'] ?? array() );
            if ( ! in_array( $user_id, $assigned, true ) ) {
                wp_die( 'Du bist nicht für diesen Dienst eingeteilt.', 'CrewBoard', array( 'response' => 403 ) );
            }
            $svc_responses = is_array( $service['responses'] ?? null ) ? $service['responses'] : array();
            $svc_responses[ (string) $user_id ] = array(
                'status' => $status,
                'reason' => $reason,
                'at'     => wp_date( 'Y-m-d\TH:i' ),
            );
            $service['responses'] = $svc_responses;
            $found = true;
            break;
        }
        unset( $service );

        if ( ! $found ) {
            wp_die( 'Dienst nicht gefunden.', 'CrewBoard', array( 'response' => 404 ) );
        }

        update_post_meta( $event_id, self::META_SERVICES, $services );

        if ( 'denied' === $status ) {
            self::notify_denial( $event_id, $service_id, $user_id, $reason, $services );
        }

        wp_safe_redirect( add_query_arg( 'crewboard', 'accepted' === $status ? 'responded_accepted' : 'responded_denied', self::portal_url() ) );
        exit;
    }

    public static function login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
        if ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) && ! self::can_manage_services( (int) $user->ID ) ) {
            return self::portal_url();
        }
        return $redirect_to;
    }


    private static function default_teams(): array {
        return array(
            'allgemein' => 'Allgemein',
            'theke'     => 'Theke',
            'technik'   => 'Technik',
            'einlass'   => 'Einlass',
            'aufbau'    => 'Aufbau',
            'reinigung' => 'Reinigung',
            'booking'   => 'Booking',
            'einkauf'   => 'Einkauf',
            'werbung'   => 'Werbung / Social Media',
        );
    }

    public static function teams(): array {
        $custom = get_option( self::OPTION_CUSTOM_TEAMS, array() );
        if ( ! is_array( $custom ) ) {
            $custom = array();
        }
        return array_merge( self::default_teams(), $custom );
    }

    private static function can_manage_members( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return user_can( $user_id, 'manage_options' ) || 'board' === get_user_meta( $user_id, self::META_LEVEL, true );
    }

    private static function can_manage_services( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return user_can( $user_id, 'manage_options' ) || in_array( get_user_meta( $user_id, self::META_LEVEL, true ), array( 'coordinator', 'board' ), true );
    }

    private static function user_is_eligible( int $user_id, string $team ): bool {
        if ( '' === $team ) {
            return '' !== get_user_meta( $user_id, self::META_LEVEL, true ) || user_can( $user_id, 'manage_options' );
        }
        $teams = get_user_meta( $user_id, self::META_TEAMS, true );
        return is_array( $teams ) && in_array( $team, $teams, true );
    }

    public static function admin_menu(): void {
        if ( ! self::can_manage_services() ) {
            return;
        }
        add_menu_page( 'CrewBoard', 'CrewBoard', 'read', 'crewboard', array( __CLASS__, 'render_admin_events' ), 'dashicons-groups', 27 );
        add_submenu_page( 'crewboard', 'Veranstaltungen', 'Veranstaltungen', 'read', 'crewboard', array( __CLASS__, 'render_admin_events' ) );
        if ( self::can_manage_members() ) {
            add_submenu_page( 'crewboard', 'Mitglieder', 'Mitglieder', 'read', 'crewboard-members', array( __CLASS__, 'render_admin_members' ) );
            add_submenu_page( 'crewboard', 'Einstellungen', 'Einstellungen', 'read', 'crewboard-settings', array( __CLASS__, 'render_admin_settings' ) );
        }
    }

    public static function render_admin_members(): void {
        if ( ! self::can_manage_members() ) {
            wp_die( 'Keine Berechtigung.' );
        }
        $all_users  = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
        $active     = array();
        $inactive   = array();
        foreach ( $all_users as $u ) {
            if ( '' !== (string) get_user_meta( $u->ID, self::META_LEVEL, true ) ) {
                $active[] = $u;
            } else {
                $inactive[] = $u;
            }
        }
        $teams      = self::teams();
        $col_count  = 2 + count( $teams );
        ?>
        <div class="wrap">
        <h1>CrewBoard – Mitglieder</h1>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Änderungen gespeichert.</p></div><?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="crewboard_save_members">
            <?php wp_nonce_field( 'crewboard_save_members' ); ?>

            <?php
            // ── Helper: render a single member row ────────────────────────
            $render_row = function( WP_User $user ) use ( $teams ): void {
                $level      = (string) get_user_meta( $user->ID, self::META_LEVEL, true );
                $user_teams = get_user_meta( $user->ID, self::META_TEAMS, true );
                $user_teams = is_array( $user_teams ) ? $user_teams : array();
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><small><?php echo esc_html( $user->user_email ); ?></small></td>
                    <td>
                        <select name="members[<?php echo esc_attr( (string) $user->ID ); ?>][level]">
                            <option value="" <?php selected( $level, '' ); ?>>Kein CrewBoard-Zugang</option>
                            <option value="member"      <?php selected( $level, 'member' ); ?>>Mitglied</option>
                            <option value="coordinator" <?php selected( $level, 'coordinator' ); ?>>Koordinator</option>
                            <option value="board"       <?php selected( $level, 'board' ); ?>>Vorstand</option>
                        </select>
                    </td>
                    <?php foreach ( $teams as $key => $label ) : ?>
                        <td><input type="checkbox" name="members[<?php echo esc_attr( (string) $user->ID ); ?>][teams][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $user_teams, true ) ); ?>></td>
                    <?php endforeach; ?>
                </tr>
                <?php
            };
            // Helper: render table head
            $render_head = function() use ( $teams ): void {
                ?><thead><tr><th>Person</th><th>CrewBoard-Stufe</th><?php foreach ( $teams as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead><?php
            };
            ?>

            <!-- ── Section 1: active members ──────────────────────── -->
            <h2>Aktive Crew-Mitglieder
                <span class="title-count theme-count"><?php echo esc_html( (string) count( $active ) ); ?></span>
            </h2>
            <p class="description">Mitglieder mit CrewBoard-Zugang. Stufe und Teamzugehörigkeit können hier geändert werden.</p>
            <p><input type="search" id="crewboard-search-active" class="regular-text" placeholder="Aktive Mitglieder suchen …" autocomplete="off"></p>
            <table class="widefat striped" id="crewboard-members-active">
                <?php $render_head(); ?>
                <tbody>
                <?php if ( empty( $active ) ) : ?>
                    <tr><td colspan="<?php echo esc_attr( (string) $col_count ); ?>"><em>Noch keine aktiven Mitglieder.</em></td></tr>
                <?php else : ?>
                    <?php foreach ( $active as $u ) { $render_row( $u ); } ?>
                <?php endif; ?>
                </tbody>
            </table>

            <hr style="margin:32px 0">

            <!-- ── Section 2: add new members ─────────────────────── -->
            <h2>Mitglieder hinzufügen
                <span class="title-count theme-count"><?php echo esc_html( (string) count( $inactive ) ); ?></span>
            </h2>
            <p class="description">WordPress-Nutzer ohne CrewBoard-Zugang. Eine Stufe zuweisen, um sie zur Crew hinzuzufügen.</p>
            <p><input type="search" id="crewboard-search-inactive" class="regular-text" placeholder="Nutzer suchen …" autocomplete="off"></p>
            <table class="widefat striped" id="crewboard-members-inactive">
                <?php $render_head(); ?>
                <tbody>
                <?php if ( empty( $inactive ) ) : ?>
                    <tr><td colspan="<?php echo esc_attr( (string) $col_count ); ?>"><em>Alle WordPress-Nutzer sind bereits aktive Crew-Mitglieder.</em></td></tr>
                <?php else : ?>
                    <?php foreach ( $inactive as $u ) { $render_row( $u ); } ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php submit_button( 'Mitglieder speichern' ); ?>
        </form>
        </div><?php
    }

    public static function save_members(): void {
        if ( ! self::can_manage_members() ) {
            wp_die( 'Keine Berechtigung.' );
        }
        check_admin_referer( 'crewboard_save_members' );
        $members = isset( $_POST['members'] ) && is_array( $_POST['members'] ) ? wp_unslash( $_POST['members'] ) : array();
        foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
            $row = isset( $members[ $user_id ] ) && is_array( $members[ $user_id ] ) ? $members[ $user_id ] : array();
            $level = sanitize_key( $row['level'] ?? '' );
            if ( ! in_array( $level, array( '', 'member', 'coordinator', 'board' ), true ) ) {
                $level = '';
            }
            $teams = isset( $row['teams'] ) && is_array( $row['teams'] ) ? array_map( 'sanitize_key', $row['teams'] ) : array();
            $teams = array_values( array_intersect( array_keys( self::teams() ), $teams ) );
            update_user_meta( $user_id, self::META_LEVEL, $level );
            update_user_meta( $user_id, self::META_TEAMS, $teams );
        }
        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=crewboard-members' ) ) );
        exit;
    }

    public static function render_admin_settings(): void {
        if ( ! self::can_manage_members() ) {
            wp_die( 'Keine Berechtigung.' );
        }
        $custom_teams = get_option( self::OPTION_CUSTOM_TEAMS, array() );
        if ( ! is_array( $custom_teams ) ) {
            $custom_teams = array();
        }
        ?>
        <div class="wrap"><h1>CrewBoard – Einstellungen</h1>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>Einstellungen gespeichert.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="crewboard_save_settings"><?php wp_nonce_field( 'crewboard_save_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Selbstzuweisung</th>
                    <td><label><input type="checkbox" name="self_assign" value="1" <?php checked( get_option( self::OPTION_SELF_ASSIGN, '1' ), '1' ); ?>> Mitglieder dürfen freie, für ihr Team freigegebene Dienste selbst übernehmen.</label></td>
                </tr>
                <tr>
                    <th scope="row">Eigene Teams</th>
                    <td>
                        <p class="description">Zusätzliche Teams ergänzend zu den vordefinierten. Der interne Schlüssel wird automatisch aus dem Namen erzeugt.</p>
                        <div id="crewboard-custom-teams-list" style="margin:10px 0;display:flex;flex-direction:column;gap:6px">
                            <?php foreach ( $custom_teams as $label ) : ?>
                                <div class="crewboard-team-row" style="display:flex;gap:6px;align-items:center">
                                    <input type="text" name="custom_teams[]" value="<?php echo esc_attr( $label ); ?>" placeholder="Teamname" class="regular-text">
                                    <button type="button" class="button crewboard-remove-team">Entfernen</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p><button type="button" class="button" id="crewboard-add-team">+ Team hinzufügen</button></p>
                        <p class="description"><strong>Vordefinierte Teams:</strong> <?php echo esc_html( implode( ', ', array_values( self::default_teams() ) ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Standard-Dienste</th>
                    <td>
                        <p class="description">Bei Events ohne Dienste werden diese Typen automatisch als Vorlage vorgeschlagen. Titel und Teamzuordnung stammen aus der Teamliste.</p>
                        <?php
                        $def_svcs = get_option( self::OPTION_DEFAULT_SERVICES, array( 'theke' => 2, 'einlass' => 2, 'einkauf' => 1 ) );
                        if ( ! is_array( $def_svcs ) ) { $def_svcs = array(); }
                        foreach ( self::teams() as $team_key => $team_label ) :
                            $is_def = array_key_exists( $team_key, $def_svcs );
                            $needed = $is_def ? max( 1, (int) $def_svcs[ $team_key ] ) : 1;
                        ?>
                            <div class="crewboard-default-svc-row">
                                <label>
                                    <input type="checkbox" name="default_services[<?php echo esc_attr( $team_key ); ?>][enabled]" value="1" <?php checked( $is_def ); ?>>
                                    <?php echo esc_html( $team_label ); ?>
                                </label>
                                <input type="number" min="1" max="50" name="default_services[<?php echo esc_attr( $team_key ); ?>][needed]" value="<?php echo esc_attr( (string) $needed ); ?>" class="small-text"> <span class="description">Personen</span>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form></div><?php
    }

    public static function save_settings(): void {
        if ( ! self::can_manage_members() ) {
            wp_die( 'Keine Berechtigung.' );
        }
        check_admin_referer( 'crewboard_save_settings' );
        update_option( self::OPTION_SELF_ASSIGN, isset( $_POST['self_assign'] ) ? '1' : '0' );

        $raw_teams    = isset( $_POST['custom_teams'] ) && is_array( $_POST['custom_teams'] ) ? wp_unslash( $_POST['custom_teams'] ) : array();
        $custom_teams = array();
        $default_keys = array_keys( self::default_teams() );
        foreach ( $raw_teams as $label ) {
            $label = sanitize_text_field( $label );
            if ( '' === $label ) { continue; }
            $key = sanitize_key( $label );
            if ( '' === $key || in_array( $key, $default_keys, true ) ) { continue; } // skip empty or collisions with defaults
            $custom_teams[ $key ] = $label;
        }
        update_option( self::OPTION_CUSTOM_TEAMS, $custom_teams );

        // Save default services (team key → needed count).
        $raw_def         = isset( $_POST['default_services'] ) && is_array( $_POST['default_services'] ) ? wp_unslash( $_POST['default_services'] ) : array();
        $def_svcs        = array();
        $valid_team_keys = array_keys( self::teams() );
        foreach ( $raw_def as $team_key => $cfg ) {
            $team_key = sanitize_key( (string) $team_key );
            if ( '' === $team_key || ! in_array( $team_key, $valid_team_keys, true ) ) { continue; }
            if ( empty( $cfg['enabled'] ) ) { continue; }
            $def_svcs[ $team_key ] = max( 1, min( 50, (int) ( $cfg['needed'] ?? 1 ) ) );
        }
        update_option( self::OPTION_DEFAULT_SERVICES, $def_svcs );

        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=crewboard-settings' ) ) );
        exit;
    }

    public static function render_admin_events(): void {
        if ( ! self::can_manage_services() ) {
            wp_die( 'Keine Berechtigung.' );
        }
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $tz       = wp_timezone();
        $now      = new DateTimeImmutable( 'today', $tz );
        $all      = self::get_events_between( new DateTimeImmutable( '-24 months', $tz ), new DateTimeImmutable( '+18 months', $tz ) );
        $upcoming = array();
        $past     = array();
        foreach ( $all as $event ) {
            $date = self::event_start_date( $event );
            if ( null !== $date && $date >= $now ) {
                $upcoming[] = $event;
            } else {
                $past[] = $event;
            }
        }
        $past = array_reverse( $past ); // most recent past event first

        // If the currently selected event is in the past, the past group must be open.
        $selected_post  = $event_id ? get_post( $event_id ) : null;
        $sel_date       = $selected_post instanceof WP_Post ? self::event_start_date( $selected_post ) : null;
        $selected_is_past = $sel_date instanceof DateTimeImmutable && $sel_date < $now;
        ?>
        <div class="wrap"><h1>CrewBoard – Veranstaltungen</h1>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>Dienste gespeichert.</p></div><?php endif; ?>
        <form method="get">
            <input type="hidden" name="page" value="crewboard">
            <div class="crewboard-event-filter">
                <input type="search" id="crewboard-event-search" class="regular-text" placeholder="Veranstaltung suchen …" autocomplete="off">
                <select name="event_id" id="crewboard-event-select">
                    <option value="">Veranstaltung auswählen …</option>
                    <?php if ( ! empty( $upcoming ) ) : ?>
                        <optgroup label="Kommende Veranstaltungen">
                            <?php foreach ( $upcoming as $event ) : ?>
                                <option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>><?php echo esc_html( self::event_date_label( $event ) . ' – ' . get_the_title( $event ) ); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ( ! empty( $past ) ) : ?>
                        <optgroup label="Vergangene Veranstaltungen" id="crewboard-past-events"<?php if ( ! $selected_is_past ) { echo ' hidden'; } ?>>
                            <?php foreach ( $past as $event ) : ?>
                                <option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>><?php echo esc_html( self::event_date_label( $event ) . ' – ' . get_the_title( $event ) ); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <?php submit_button( 'Öffnen', 'secondary', '', false ); ?>
            </div>
            <label class="crewboard-past-toggle">
                <input type="checkbox" id="crewboard-show-past"<?php checked( $selected_is_past ); ?>> Vergangene Veranstaltungen anzeigen
            </label>
        </form>
        <?php if ( $event_id && 'event' === get_post_type( $event_id ) ) : ?>
            <hr><h2><?php echo esc_html( get_the_title( $event_id ) ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="crewboard_save_event_services"><input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
                <?php wp_nonce_field( 'crewboard_save_event_services_' . $event_id ); ?>
                <?php self::render_services_editor( $event_id ); ?>
                <?php submit_button( 'Dienste speichern' ); ?>
            </form>
        <?php endif; ?></div><?php
    }

    private static function render_services_editor( int $event_id ): void {
        $services     = self::get_services( $event_id );
        $is_prefilled = empty( $services );
        if ( $is_prefilled ) {
            $services = self::get_default_service_definitions();
            if ( empty( $services ) ) {
                $services[]   = self::empty_service();
                $is_prefilled = false;
            }
        }
        $users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name' ) ) );
        $users = array_values( array_filter( $users, static fn( $user ): bool => self::user_is_eligible( (int) $user->ID, '' ) ) );
        if ( $is_prefilled ) { ?>
            <div class="notice notice-info inline"><p>&#x26A1; Noch keine Dienste für dieses Event vorhanden – Standard-Dienste wurden als Vorlage eingetragen. Vor dem Speichern anpassen.</p></div>
        <?php } ?>
        <table class="widefat striped" id="crewboard-services-table"><thead><tr><th>Dienst</th><th>Beginn</th><th>Ende</th><th>Bedarf</th><th>Team</th><th>Mitglieder</th><th></th></tr></thead><tbody><?php foreach ( $services as $index => $service ) { self::render_service_row( $index, $service, $users ); } ?></tbody></table>
        <p><button type="button" class="button" id="crewboard-add-service">+ Dienst hinzufügen</button></p><script type="text/template" id="crewboard-service-template"><?php self::render_service_row( '__INDEX__', self::empty_service(), $users ); ?></script><?php
    }

    public static function save_services_from_admin_page(): void {
        if ( ! self::can_manage_services() ) { wp_die( 'Keine Berechtigung.' ); }
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        check_admin_referer( 'crewboard_save_event_services_' . $event_id );
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) { wp_die( 'Ungültige Veranstaltung.' ); }
        self::persist_services_from_request( $event_id );
        wp_safe_redirect( add_query_arg( array( 'page' => 'crewboard', 'event_id' => $event_id, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function persist_services_from_request( int $post_id ): void {
        $old_services = self::get_services( $post_id );

        // Index old services by UUID for quick response preservation.
        $old_by_id = array();
        foreach ( $old_services as $svc ) {
            if ( ! empty( $svc['id'] ) ) {
                $old_by_id[ $svc['id'] ] = $svc;
            }
        }

        $raw      = isset( $_POST['crewboard_services'] ) && is_array( $_POST['crewboard_services'] ) ? wp_unslash( $_POST['crewboard_services'] ) : array();
        $services = array();
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $title = sanitize_text_field( $row['title'] ?? '' );
            if ( '' === $title ) { continue; }
            $team = sanitize_key( $row['team'] ?? '' );
            if ( ! array_key_exists( $team, self::teams() ) ) { $team = ''; }
            $assigned = isset( $row['assigned'] ) && is_array( $row['assigned'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $row['assigned'] ) ) ) ) : array();
            $assigned = array_values( array_filter( $assigned, static fn( int $uid ): bool => self::user_is_eligible( $uid, '' ) ) );

            $svc_id        = sanitize_text_field( $row['id'] ?? wp_generate_uuid4() );
            $old_svc       = $old_by_id[ $svc_id ] ?? null;
            $old_assigned  = $old_svc ? array_map( 'intval', $old_svc['assigned'] ?? array() ) : array();
            $old_responses = ( $old_svc && is_array( $old_svc['responses'] ?? null ) ) ? $old_svc['responses'] : array();

            // Keep response for users who remain; set pending for newly assigned users.
            $responses = array();
            foreach ( $assigned as $uid ) {
                $responses[ (string) $uid ] = in_array( $uid, $old_assigned, true )
                    ? ( $old_responses[ (string) $uid ] ?? array( 'status' => 'pending', 'reason' => '', 'at' => null ) )
                    : array( 'status' => 'pending', 'reason' => '', 'at' => null );
            }

            $services[] = array(
                'id'        => $svc_id,
                'title'     => $title,
                'start'     => self::sanitize_datetime_local( $row['start'] ?? '' ),
                'end'       => self::sanitize_datetime_local( $row['end'] ?? '' ),
                'needed'    => max( 1, min( 50, absint( $row['needed'] ?? 1 ) ) ),
                'team'      => $team,
                'assigned'  => $assigned,
                'responses' => $responses,
            );
        }
        update_post_meta( $post_id, self::META_SERVICES, $services );

        self::notify_assignment_changes( $post_id, $old_services, $services );
    }

    private static function notify_assignment_changes( int $post_id, array $old_services, array $new_services ): void {
        $event_title = get_the_title( $post_id );
        $site_name   = get_bloginfo( 'name' );
        $portal_url  = self::portal_url();

        // Index old assignments by service ID.
        $old_map = array();
        foreach ( $old_services as $svc ) {
            $old_map[ $svc['id'] ] = array(
                'title'    => $svc['title'],
                'assigned' => array_map( 'intval', $svc['assigned'] ?? array() ),
            );
        }

        // Index new assignments by service ID.
        $new_map = array();
        foreach ( $new_services as $svc ) {
            $new_map[ $svc['id'] ] = array(
                'title'    => $svc['title'],
                'assigned' => array_map( 'intval', $svc['assigned'] ?? array() ),
            );
        }

        // Notify users added or removed in existing / new services.
        foreach ( $new_map as $svc_id => $new_svc ) {
            $old_assigned = $old_map[ $svc_id ]['assigned'] ?? array();
            $added        = array_diff( $new_svc['assigned'], $old_assigned );
            $removed      = array_diff( $old_assigned, $new_svc['assigned'] );

            foreach ( $added as $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) { continue; }
                wp_mail(
                    $user->user_email,
                    sprintf( '[%s] Du wurdest für einen Dienst eingeteilt', $site_name ),
                    sprintf(
                        "Hallo %s,\n\ndu wurdest für folgenden Dienst eingeteilt:\n\nVeranstaltung: %s\nDienst: %s\n\nDeine Dienste im CrewBoard:\n%s\n\nViele Grüße\n%s",
                        $user->display_name,
                        $event_title,
                        $new_svc['title'],
                        $portal_url,
                        $site_name
                    )
                );
            }

            foreach ( $removed as $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) { continue; }
                wp_mail(
                    $user->user_email,
                    sprintf( '[%s] Deine Diensteinteilung wurde geändert', $site_name ),
                    sprintf(
                        "Hallo %s,\n\ndu wurdest aus folgendem Dienst ausgetragen:\n\nVeranstaltung: %s\nDienst: %s\n\nDeine aktuellen Dienste findest du hier:\n%s\n\nViele Grüße\n%s",
                        $user->display_name,
                        $event_title,
                        $new_svc['title'],
                        $portal_url,
                        $site_name
                    )
                );
            }
        }

        // Notify users whose entire service was deleted.
        foreach ( $old_map as $svc_id => $old_svc ) {
            if ( isset( $new_map[ $svc_id ] ) ) { continue; }
            foreach ( $old_svc['assigned'] as $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) { continue; }
                wp_mail(
                    $user->user_email,
                    sprintf( '[%s] Deine Diensteinteilung wurde geändert', $site_name ),
                    sprintf(
                        "Hallo %s,\n\nder Dienst \"%s\" für die Veranstaltung \"%s\" wurde entfernt. Deine Einteilung für diesen Dienst ist damit hinfällig.\n\nDeine aktuellen Dienste findest du hier:\n%s\n\nViele Grüße\n%s",
                        $user->display_name,
                        $old_svc['title'],
                        $event_title,
                        $portal_url,
                        $site_name
                    )
                );
            }
        }
    }

    private static function notify_denial( int $event_id, string $service_id, int $denying_user_id, string $reason, array $services ): void {
        $member = get_userdata( $denying_user_id );
        if ( ! $member ) {
            return;
        }
        $event_title = get_the_title( $event_id );
        $site_name   = get_bloginfo( 'name' );
        $admin_url   = add_query_arg( array( 'page' => 'crewboard', 'event_id' => $event_id ), admin_url( 'admin.php' ) );
        $svc_title   = '';
        foreach ( $services as $svc ) {
            if ( ( $svc['id'] ?? '' ) === $service_id ) {
                $svc_title = $svc['title'];
                break;
            }
        }
        $reason_line = '' !== $reason ? "\n\nGrund: " . $reason : '';
        $subject     = sprintf( '[%s] Dienst abgelehnt von %s', $site_name, $member->display_name );
        $body_tpl    = "Hallo %s,\n\n" . sprintf(
            "%s hat den Dienst \"%s\" für die Veranstaltung \"%s\" abgelehnt.%s\n\nZur Übersicht:\n%s\n\nViele Grüße\n%s",
            $member->display_name,
            $svc_title,
            $event_title,
            $reason_line,
            $admin_url,
            $site_name
        );
        foreach ( get_users( array( 'fields' => array( 'ID', 'user_email', 'display_name' ) ) ) as $mgr ) {
            if ( (int) $mgr->ID === $denying_user_id || ! self::can_manage_services( (int) $mgr->ID ) ) {
                continue;
            }
            wp_mail( $mgr->user_email, $subject, sprintf( $body_tpl, $mgr->display_name ) );
        }
    }

    private static function portal_url(): string {
        $page_id = (int) get_option( self::PAGE_OPTION );
        return $page_id ? get_permalink( $page_id ) : home_url( '/intern/' );
    }

    private static function get_services( int $event_id ): array {
        $services = get_post_meta( $event_id, self::META_SERVICES, true );
        if ( ! is_array( $services ) ) {
            return array();
        }
        foreach ( $services as &$service ) {
            if ( empty( $service['id'] ) ) {
                $service['id'] = wp_generate_uuid4();
            }
        }
        unset( $service );
        return $services;
    }

    private static function get_events_between( DateTimeImmutable $start, DateTimeImmutable $end ): array {
        // Events Manager speichert Events als WordPress-Beitragstyp "event". Die Datumswerte
        // werden über EM_Event gelesen; der Query bleibt dadurch mit EM 7.x kompatibel.
        $query = new WP_Query(
            array(
                'post_type'      => 'event',
                'post_status'    => 'publish',
                'posts_per_page' => 300,
                'orderby'        => 'date',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            )
        );
        $events = array();
        foreach ( $query->posts as $post ) {
            $date = self::event_start_date( $post );
            if ( $date && $date >= $start->setTime( 0, 0 ) && $date <= $end->setTime( 23, 59, 59 ) ) {
                $events[] = $post;
            }
        }
        usort(
            $events,
            static fn( WP_Post $a, WP_Post $b ): int => ( self::event_start_date( $a )?->getTimestamp() ?? PHP_INT_MAX ) <=> ( self::event_start_date( $b )?->getTimestamp() ?? PHP_INT_MAX )
        );
        return $events;
    }

    private static function event_start_date( WP_Post $event ): ?DateTimeImmutable {
        if ( class_exists( 'EM_Event' ) ) {
            try {
                $em_event = new EM_Event( $event->ID, 'post_id' );
                if ( ! empty( $em_event->event_start_date ) ) {
                    $time = ! empty( $em_event->event_start_time ) ? $em_event->event_start_time : '00:00:00';
                    $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $em_event->event_start_date . ' ' . $time, wp_timezone() );
                    if ( $date ) {
                        return $date;
                    }
                }
            } catch ( Throwable $e ) {
                // Fallback below.
            }
        }

        foreach ( array( '_event_start_date', 'event_start_date' ) as $key ) {
            $raw = (string) get_post_meta( $event->ID, $key, true );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw, $match ) ) {
                $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $match[0], wp_timezone() );
                if ( $date ) {
                    return $date;
                }
            }
        }
        return null;
    }

    private static function event_date_label( WP_Post $event ): string {
        $date = self::event_start_date( $event );
        return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : 'Termin offen';
    }

    private static function format_service_time( array $service, WP_Post $event ): string {
        if ( ! empty( $service['start'] ) ) {
            $start = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $service['start'], wp_timezone() );
            $end = ! empty( $service['end'] ) ? DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $service['end'], wp_timezone() ) : false;
            if ( $start ) {
                $text = wp_date( 'd.m., H:i', $start->getTimestamp(), wp_timezone() ) . ' Uhr';
                if ( $end ) {
                    $text .= ' – ' . wp_date( 'd.m., H:i', $end->getTimestamp(), wp_timezone() ) . ' Uhr';
                }
                return $text;
            }
        }
        return self::event_date_label( $event );
    }

    private static function event_has_user_service( int $event_id, int $user_id ): bool {
        foreach ( self::get_services( $event_id ) as $service ) {
            if ( in_array( $user_id, array_map( 'intval', $service['assigned'] ?? array() ), true ) ) {
                return true;
            }
        }
        return false;
    }

    // ── ICS Calendar Feed ─────────────────────────────────────────────────────

    public static function ics_query_vars( array $vars ): array {
        $vars[] = 'crewboard_ics_feed';
        return $vars;
    }

    public static function maybe_serve_ics_feed(): void {
        if ( ! get_query_var( 'crewboard_ics_feed' ) ) {
            return;
        }
        // Drain any output buffers opened by themes or plugins.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        $raw_token = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';
        $user_id   = self::user_id_from_ics_token( $raw_token );
        if ( 0 === $user_id ) {
            // Generic 403 — never reveal which user or token was tried.
            status_header( 403 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'Cache-Control: no-store' );
            exit;
        }
        $ics = self::build_ics( $user_id );
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="crewboard.ics"' );
        header( 'Cache-Control: no-store, private' );
        header( 'Pragma: no-cache' );
        header( 'X-Robots-Tag: noindex' );
        echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function user_id_from_ics_token( string $raw_token ): int {
        // Tokens are exactly 64 lowercase hex characters (256 bits of entropy).
        if ( 64 !== strlen( $raw_token ) || ! ctype_xdigit( $raw_token ) ) {
            return 0;
        }
        $users = get_users(
            array(
                'meta_key'     => self::META_ICS_TOKEN,
                'meta_value'   => $raw_token,
                'meta_compare' => '=',
                'number'       => 1,
                'fields'       => 'ID',
            )
        );
        if ( empty( $users ) ) {
            return 0;
        }
        $user_id = (int) $users[0];
        $stored  = (string) get_user_meta( $user_id, self::META_ICS_TOKEN, true );
        // hash_equals prevents timing-based enumeration even after the DB lookup.
        return ( '' !== $stored && hash_equals( $stored, $raw_token ) ) ? $user_id : 0;
    }

    private static function get_or_create_ics_token( int $user_id ): string {
        $existing = (string) get_user_meta( $user_id, self::META_ICS_TOKEN, true );
        if ( '' !== $existing && 64 === strlen( $existing ) && ctype_xdigit( $existing ) ) {
            return $existing;
        }
        return self::create_ics_token( $user_id );
    }

    private static function create_ics_token( int $user_id ): string {
        $token = bin2hex( random_bytes( 32 ) ); // 64 hex chars = 256 bits
        update_user_meta( $user_id, self::META_ICS_TOKEN, $token );
        return $token;
    }

    public static function rotate_ics_token(): void {
        if ( ! is_user_logged_in() ) {
            auth_redirect();
        }
        $nonce = isset( $_POST['crewboard_ics_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['crewboard_ics_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'crewboard_rotate_ics_' . get_current_user_id() ) ) {
            wp_die( 'Sicherheitsüberprüfung fehlgeschlagen.', 'CrewBoard', array( 'response' => 403 ) );
        }
        self::create_ics_token( get_current_user_id() );
        wp_safe_redirect( add_query_arg( 'crewboard', 'ics_rotated', self::portal_url() ) );
        exit;
    }

    private static function render_ics_subscription( int $user_id ): string {
        $token    = self::get_or_create_ics_token( $user_id );
        $feed_url = home_url( '/crewboard/calendar.ics' ) . '?token=' . rawurlencode( $token );
        $webcal   = preg_replace( '/^https?:\/\//', 'webcal://', $feed_url ) ?? $feed_url;
        ob_start();
        ?>
        <section class="crewboard-section crewboard-ics-section">
            <div class="crewboard-section-title"><h2>Kalender abonnieren</h2></div>
            <div class="crewboard-ics-box">
                <p class="crewboard-ics-intro">Abonniere deinen persönlichen Kalender in Apple Kalender, Google Calendar, Outlook oder einer anderen kompatiblen Anwendung. Dieser Link ist wie ein Passwort&nbsp;&ndash; teile ihn nicht.</p>
                <div class="crewboard-ics-url-row">
                    <input type="text" class="crewboard-ics-url-input" value="<?php echo esc_attr( $feed_url ); ?>" readonly aria-label="Kalender-Link">
                    <button type="button" class="crewboard-button crewboard-ics-copy-btn" data-url="<?php echo esc_attr( $feed_url ); ?>">Kopieren</button>
                    <a href="<?php echo esc_url( $webcal ); ?>" class="crewboard-button crewboard-ics-subscribe-btn">Abonnieren</a>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crewboard-ics-rotate-form" onsubmit="return confirm('Bisherigen Kalender-Link sofort ungültig machen und einen neuen erstellen?');">
                    <input type="hidden" name="action" value="crewboard_rotate_ics_token">
                    <?php wp_nonce_field( 'crewboard_rotate_ics_' . $user_id, 'crewboard_ics_nonce' ); ?>
                    <button type="submit" class="crewboard-button crewboard-ics-rotate-btn">Neuen Link erstellen</button>
                </form>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    // ── ICS Feed Builder ──────────────────────────────────────────────────────

    private static function build_ics( int $user_id ): string {
        $tz      = wp_timezone();
        $domain  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
        $dtstamp = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );

        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CrewBoard//CrewBoard//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::ics_escape( get_bloginfo( 'name' ) . ' – CrewBoard' ),
            'X-WR-TIMEZONE:' . self::ics_escape( $tz->getName() ),
        );

        $window_start = new DateTimeImmutable( '-3 months', $tz );
        $window_end   = new DateTimeImmutable( '+18 months', $tz );
        $events       = self::get_events_between( $window_start, $window_end );

        foreach ( $events as $event ) {
            // Public EM event entry.
            $vevent = self::ics_vevent_em_event( $event, $domain, $dtstamp, $tz );
            if ( null !== $vevent ) {
                $lines = array_merge( $lines, $vevent );
            }
            // User's personally assigned services.
            foreach ( self::get_services( $event->ID ) as $service ) {
                if ( ! in_array( $user_id, array_map( 'intval', $service['assigned'] ?? array() ), true ) ) {
                    continue;
                }
                $svc_vevent = self::ics_vevent_service( $service, $event, $user_id, $domain, $dtstamp, $tz );
                if ( null !== $svc_vevent ) {
                    $lines = array_merge( $lines, $svc_vevent );
                }
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode( "\r\n", array_map( array( __CLASS__, 'ics_fold' ), $lines ) ) . "\r\n";
    }

    private static function ics_vevent_em_event( WP_Post $event, string $domain, string $dtstamp, DateTimeZone $tz ): ?array {
        $dtstart = self::event_start_date( $event );
        if ( null === $dtstart ) {
            return null;
        }

        $all_day     = false;
        $dtend       = null;
        $description = '';
        $location    = '';

        if ( class_exists( 'EM_Event' ) ) {
            try {
                $em      = new EM_Event( $event->ID, 'post_id' );
                $all_day = ! empty( $em->event_all_day );

                if ( ! empty( $em->event_end_date ) ) {
                    $end_time = ! empty( $em->event_end_time ) ? $em->event_end_time : ( $all_day ? '00:00:00' : '23:59:00' );
                    $parsed   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $em->event_end_date . ' ' . $end_time, $tz );
                    if ( $parsed ) {
                        // RFC 5545: DTEND for all-day is the day after the last day.
                        $dtend = $all_day ? $parsed->modify( '+1 day' ) : $parsed;
                    }
                }

                $notes = wp_strip_all_tags( (string) ( $em->event_notes ?? '' ) );
                if ( '' !== $notes ) {
                    $description = $notes;
                }

                if ( method_exists( $em, 'get_location' ) ) {
                    $loc = $em->get_location();
                    if ( $loc instanceof EM_Location ) {
                        $location = implode( ', ', array_filter( array(
                            $loc->location_name    ?? '',
                            $loc->location_address ?? '',
                            $loc->location_city    ?? '',
                        ) ) );
                    }
                }
            } catch ( Throwable $e ) {
                // Use what we have from the WP post.
            }
        }

        if ( null === $dtend && ! $all_day ) {
            $dtend = $dtstart->modify( '+2 hours' );
        }

        $modified = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $event->post_modified_gmt );
        $last_mod = $modified ? $modified->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' ) : $dtstamp;

        $lines   = array( 'BEGIN:VEVENT' );
        $lines[] = 'UID:em-event-' . $event->ID . '@' . $domain;
        $lines[] = 'DTSTAMP:' . $dtstamp;
        $lines[] = 'LAST-MODIFIED:' . $last_mod;
        $lines[] = self::ics_dt_line( 'DTSTART', $dtstart, $all_day );
        if ( null !== $dtend ) {
            $lines[] = self::ics_dt_line( 'DTEND', $dtend, $all_day );
        }
        $lines[] = 'SUMMARY:' . self::ics_escape( get_the_title( $event ) );
        if ( '' !== $description ) {
            $lines[] = 'DESCRIPTION:' . self::ics_escape( $description );
        }
        if ( '' !== $location ) {
            $lines[] = 'LOCATION:' . self::ics_escape( $location );
        }
        $url = get_permalink( $event->ID );
        if ( $url ) {
            $lines[] = 'URL:' . self::ics_escape( $url );
        }
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    private static function ics_vevent_service( array $service, WP_Post $event, int $user_id, string $domain, string $dtstamp, DateTimeZone $tz ): ?array {
        $dtstart = null;
        $dtend   = null;

        if ( ! empty( $service['start'] ) ) {
            $dtstart = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $service['start'], $tz ) ?: null;
        }
        if ( ! empty( $service['end'] ) ) {
            $dtend = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $service['end'], $tz ) ?: null;
        }
        if ( null === $dtstart ) {
            $dtstart = self::event_start_date( $event );
        }
        if ( null === $dtstart ) {
            return null;
        }
        if ( null === $dtend ) {
            $dtend = $dtstart->modify( '+2 hours' );
        }

        $responses   = is_array( $service['responses'] ?? null ) ? $service['responses'] : array();
        $resp        = $responses[ (string) $user_id ] ?? array();
        $resp_status = $resp['status'] ?? 'pending';
        $resp_reason = $resp['reason'] ?? '';

        $desc_parts   = array();
        $desc_parts[] = 'Veranstaltung: ' . get_the_title( $event );
        if ( 'accepted' === $resp_status ) {
            $desc_parts[] = 'Status: Zugesagt';
        } elseif ( 'denied' === $resp_status ) {
            $desc_parts[] = 'Status: Abgelehnt';
            if ( '' !== $resp_reason ) {
                $desc_parts[] = 'Grund: ' . $resp_reason;
            }
        }

        $modified = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $event->post_modified_gmt );
        $last_mod = $modified ? $modified->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' ) : $dtstamp;

        $lines   = array( 'BEGIN:VEVENT' );
        $lines[] = 'UID:crewboard-service-' . $service['id'] . '@' . $domain;
        $lines[] = 'DTSTAMP:' . $dtstamp;
        $lines[] = 'LAST-MODIFIED:' . $last_mod;
        $lines[] = self::ics_dt_line( 'DTSTART', $dtstart, false );
        $lines[] = self::ics_dt_line( 'DTEND', $dtend, false );
        $lines[] = 'SUMMARY:' . self::ics_escape( $service['title'] . ' – ' . get_the_title( $event ) );
        $lines[] = 'DESCRIPTION:' . self::ics_escape( implode( "\n", $desc_parts ) );
        if ( 'denied' === $resp_status ) {
            $lines[] = 'STATUS:CANCELLED';
        }
        $url = get_permalink( $event->ID );
        if ( $url ) {
            $lines[] = 'URL:' . self::ics_escape( $url );
        }
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    private static function ics_dt_line( string $prop, DateTimeImmutable $dt, bool $all_day ): string {
        if ( $all_day ) {
            return $prop . ';VALUE=DATE:' . $dt->format( 'Ymd' );
        }
        return $prop . ':' . $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    }

    private static function ics_escape( string $value ): string {
        // RFC 5545 §3.3.11: escape backslash, semicolon, comma, and newlines.
        $value = str_replace( '\\', '\\\\', $value );
        $value = str_replace( ';', '\\;', $value );
        $value = str_replace( ',', '\\,', $value );
        $value = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );
        return $value;
    }

    private static function ics_fold( string $line ): string {
        // RFC 5545 §3.1: content lines MUST NOT exceed 75 octets (CRLF not counted).
        // Continuation lines begin with a single SPACE (1 octet).
        if ( strlen( $line ) <= 75 ) {
            return $line;
        }
        $result = '';
        $pos    = 0;
        $max    = 75;
        $len    = strlen( $line );
        while ( $pos < $len ) {
            $take = min( $max, $len - $pos );
            // Step back while we are pointing at a UTF-8 continuation byte (10xxxxxx).
            while ( $take > 1 && ( ord( $line[ $pos + $take - 1 ] ) & 0xC0 ) === 0x80 ) {
                $take--;
            }
            $chunk = substr( $line, $pos, $take );
            $pos  += $take;
            if ( $pos < $len ) {
                $result .= $chunk . "\r\n ";
                $max = 74; // continuation: 1 byte SPACE + 74 bytes content = 75 total
            } else {
                $result .= $chunk;
            }
        }
        return $result;
    }
}

register_activation_hook( __FILE__, array( 'CrewBoard', 'activate' ) );
CrewBoard::init();
