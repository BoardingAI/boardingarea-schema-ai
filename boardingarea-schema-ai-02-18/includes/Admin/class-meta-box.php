<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Admin;

use BoardingArea\SchemaAI\Api\OpenAI_Handler;
use BoardingArea\SchemaAI\Builder\Schema_Builder;
use BoardingArea\SchemaAI\Core;
use BoardingArea\SchemaAI\Validation\Schema_Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Meta_Box {

    public const NONCE_ACTION = 'basai_meta_box_action';
    public const NONCE_NAME   = 'basai_meta_box_nonce';

    public const META_KEY_LIVE          = '_basai_schema_json';
    public const META_KEY_DRAFT         = '_basai_schema_json_draft';
    public const META_KEY_JUSTIFICATION = '_basai_schema_justification';
    public const META_KEY_TEMPLATE_ID   = '_basai_schema_template_id';
    public const META_KEY_SUMMARY       = '_basai_schema_summary';
    public const META_KEY_LAST_ERROR    = '_basai_schema_last_error';
    public const META_KEY_GENERATED_AT  = '_basai_schema_generated_at';
    public const META_KEY_REVIEWED_TYPE = '_basai_schema_reviewed_type';
    public const META_KEY_VALIDATION    = '_basai_schema_validation';
    public const META_KEY_MISSING_INFO  = '_basai_schema_missing_info'; // New key

    public function init(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post', [ $this, 'save_meta_data' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_basai_generate_now', [ $this, 'handle_ajax_generate_now' ] );
        add_action( 'wp_ajax_basai_validate_schema', [ $this, 'handle_ajax_validate_schema' ] );
    }

    public function add_meta_box(): void {
        add_meta_box(
            'basai_schema_box',
            'BoardingArea Schema AI',
            [ $this, 'render_meta_box' ],
            [ 'post', 'page' ],
            'normal',
            'high'
        );
    }

    public function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

        $live   = (string) get_post_meta( $post->ID, self::META_KEY_LIVE, true );
        $draft  = (string) get_post_meta( $post->ID, self::META_KEY_DRAFT, true );
        $just   = (string) get_post_meta( $post->ID, self::META_KEY_JUSTIFICATION, true );
        $tmpl   = (string) get_post_meta( $post->ID, self::META_KEY_TEMPLATE_ID, true );
        $err    = (string) get_post_meta( $post->ID, self::META_KEY_LAST_ERROR, true );
        $gen_at = (int) get_post_meta( $post->ID, self::META_KEY_GENERATED_AT, true );
        $reviewed = (string) get_post_meta( $post->ID, self::META_KEY_REVIEWED_TYPE, true );
        $validation_raw = (string) get_post_meta( $post->ID, self::META_KEY_VALIDATION, true );

        $missing_info_raw = (string) get_post_meta( $post->ID, self::META_KEY_MISSING_INFO, true );
        $missing_info = [];
        if ( '' !== $missing_info_raw ) {
            $decoded = json_decode( $missing_info_raw, true );
            if ( is_array( $decoded ) ) {
                $missing_info = $decoded;
            }
        }

        $validation = [];
        if ( '' !== $validation_raw ) {
            $decoded_validation = json_decode( $validation_raw, true );
            if ( is_array( $decoded_validation ) ) {
                $validation = $decoded_validation;
            }
        }
        $validation_errors = is_array( $validation['errors'] ?? null ) ? $validation['errors'] : [];
        $validation_warnings = is_array( $validation['warnings'] ?? null ) ? $validation['warnings'] : [];
        $validation_summary = '';
        $validation_messages = [];
        if ( ! empty( $validation_errors ) || ! empty( $validation_warnings ) ) {
            $validation_summary = count( $validation_errors ) . ' errors, ' . count( $validation_warnings ) . ' warnings';
            $issues = array_slice( $validation_errors, 0, 3 );
            if ( count( $issues ) < 3 ) {
                $issues = array_merge( $issues, array_slice( $validation_warnings, 0, 3 - count( $issues ) ) );
            }
            foreach ( $issues as $issue ) {
                if ( is_array( $issue ) && isset( $issue['message'] ) ) {
                    $validation_messages[] = (string) $issue['message'];
                } elseif ( is_string( $issue ) ) {
                    $validation_messages[] = $issue;
                }
            }
        }

        $val = ( '' !== $draft ) ? $draft : $live;

        $supported = Schema_Builder::get_supported_types();
        $review_types = Schema_Builder::get_reviewed_types();
        if ( '' === $tmpl ) {
            $tmpl = 'Auto';
        }

        $active_label = $supported[ $tmpl ] ?? $tmpl;

        ?>
        <div class="basai-wrapper">

            <div class="basai-header">
                <div class="basai-controls">
                    <div class="basai-control-item">
                        <label for="basai-type-selector" class="basai-label">Schema Type</label>
                        <select id="basai-type-selector" class="basai-select">
                            <?php foreach ( $supported as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tmpl, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="basai-control-item basai-review-control <?php echo ( $tmpl === 'Review' ? 'visible' : '' ); ?>">
                        <label for="basai-reviewed-selector" class="basai-label">Review Target</label>
                        <select id="basai-reviewed-selector" class="basai-select">
                            <option value="">Auto-Detect</option>
                            <?php foreach ( $review_types as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $reviewed, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="button" id="basai-generate-btn" class="button button-primary basai-btn-large">
                        <span class="dashicons dashicons-update"></span> Generate Schema
                    </button>
                    <button type="button" id="basai-generate-save-btn" class="button button-secondary basai-btn-large">
                        <span class="dashicons dashicons-yes"></span> Generate &amp; Save
                    </button>
                </div>

                <div class="basai-meta-status">
                    <div id="basai-timestamp-pill" class="basai-status-pill <?php echo $gen_at ? 'has-date' : ''; ?>">
                        <span class="dashicons dashicons-clock"></span>
                        <span id="basai-timestamp-text">
                            <?php if ( $gen_at ) : ?>
                                Generated: <?php echo esc_html( gmdate( 'M j, H:i', $gen_at ) ); ?>
                            <?php else : ?>
                                Not generated yet
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div id="basai-status-bar" class="basai-status-pill status-ready">
                        <span class="dashicons status-icon-dash dashicons-minus"></span>
                        <span id="basai-status-text">Ready</span>
                    </div>
                </div>
            </div>

            <div class="basai-action-bar">
                <div class="action-bar-status">
                    <span class="dashicons dashicons-category"></span>
                    <span class="template-indicator" title="Current Template">
                        Using: <strong id="basai-current-type"><?php echo esc_html( $active_label ); ?></strong>
                    </span>
                </div>
                <div class="action-bar-tools">
                    <button type="button" id="basai-validate-btn" class="button button-secondary">
                        <span class="dashicons dashicons-yes"></span> Validate JSON
                    </button>
                    <button type="button" id="basai-test-validator-btn" class="button button-secondary">
                        <span class="dashicons dashicons-external"></span> Google Test
                    </button>
                </div>
            </div>

            <div class="basai-feedback-area">
                <?php if ( '' !== $err ) : ?>
                    <div class="basai-notice basai-notice-error">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="notice-content">
                            <strong>Error:</strong> <?php echo esc_html( $err ); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $missing_info ) ) : ?>
                    <div class="basai-notice basai-notice-warning">
                        <span class="dashicons dashicons-info-outline"></span>
                        <div class="notice-content">
                            <strong>Missing Information Detected:</strong>
                            <p>The AI suggests adding the following details to your post for better schema coverage:</p>
                            <ul class="basai-notice-list">
                                <?php foreach ( $missing_info as $info ) : ?>
                                    <li><?php echo esc_html( $info ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( '' !== $validation_summary ) : ?>
                    <div class="basai-notice <?php echo ! empty( $validation_errors ) ? 'basai-notice-error' : 'basai-notice-warning'; ?>">
                        <span class="dashicons <?php echo ! empty( $validation_errors ) ? 'dashicons-warning' : 'dashicons-info-outline'; ?>"></span>
                        <div class="notice-content">
                            <strong><?php echo ! empty( $validation_errors ) ? 'Schema validation failed:' : 'Schema validation warnings:'; ?></strong>
                            <?php echo esc_html( $validation_summary ); ?>
                            <?php if ( ! empty( $validation_messages ) ) : ?>
                                <ul class="basai-notice-list">
                                    <?php foreach ( $validation_messages as $msg ) : ?>
                                        <li><?php echo esc_html( $msg ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div id="basai-insight-box" class="basai-insight-card <?php echo ( '' === $just ) ? 'hidden' : ''; ?>">
                    <div class="insight-icon">
                        <span class="dashicons dashicons-lightbulb"></span>
                    </div>
                    <div class="insight-body">
                        <strong>AI Insight:</strong>
                        <span id="basai-insight-text"><?php echo esc_html( $just ); ?></span>
                    </div>
                </div>

            </div>

            <div class="basai-editor-stack">
                <div id="basai-graph-card" class="basai-editor-card basai-collapsible">
                    <div class="basai-editor-header basai-collapsible-header">
                        <div class="editor-title">
                            <span class="dashicons dashicons-networking"></span> Schema Relationships
                        </div>
                        <div class="editor-actions">
                            <button type="button" class="basai-collapse-toggle" aria-expanded="true" aria-controls="basai-graph-body">
                                <span class="dashicons dashicons-arrow-up-alt2"></span>
                                <span class="screen-reader-text">Collapse Schema Relationships</span>
                            </button>
                        </div>
                    </div>

                    <div id="basai-graph-body" class="basai-collapsible-body">
                        <div class="basai-graph-card">
                            <div class="basai-graph-toolbar">
                                <div class="basai-graph-tabs" role="tablist" aria-label="Schema inspector views">
                                    <button type="button" class="basai-graph-tab is-active" data-mode="graph" role="tab" aria-selected="true">Relationships</button>
                                    <button type="button" class="basai-graph-tab" data-mode="rich" role="tab" aria-selected="false">Rich Results</button>
                                    <button type="button" class="basai-graph-tab" data-mode="validator" role="tab" aria-selected="false">Validator</button>
                                </div>
                            </div>

                            <div id="basai-graph-mode">
                                <div id="basai-graph-summary" class="basai-graph-summary"></div>
                                <div id="basai-graph-errors" class="basai-graph-errors"></div>
                                <div id="basai-graph-wrap" class="basai-graph-wrap">
                                    <svg id="basai-graph-lines" class="basai-graph-lines" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"></svg>
                                    <svg id="basai-graph-labels" class="basai-graph-labels" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"></svg>
                                    <div id="basai-graph-rows" class="basai-graph-rows"></div>
                                </div>
                            </div>

                            <div id="basai-summary-mode" class="basai-hidden"></div>
                        </div>
                    </div>
                </div>

                <div id="basai-json-card" class="basai-editor-card basai-collapsible">
                    <div class="basai-editor-header basai-collapsible-header">
                        <div class="editor-title">
                            <span class="dashicons dashicons-code-standards"></span> Schema JSON-LD
                            <?php if ( '' !== $draft ) : ?>
                                <span class="basai-badge warning">Draft Mode</span>
                            <?php endif; ?>
                        </div>
                        <div class="editor-actions">
                            <button type="button" class="basai-collapse-toggle" aria-expanded="true" aria-controls="basai-json-body">
                                <span class="dashicons dashicons-arrow-up-alt2"></span>
                                <span class="screen-reader-text">Collapse Schema JSON-LD</span>
                            </button>
                        </div>
                    </div>

                    <div id="basai-json-body" class="basai-collapsible-body">
                        <div class="basai-editor-wrap">
                            <textarea id="basai-json-editor" name="<?php echo esc_attr( self::META_KEY_LIVE ); ?>" spellcheck="false"><?php echo esc_textarea( $val ); ?></textarea>

                            <input type="hidden" id="basai-post-id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
                            <input type="hidden" id="basai-justification-input" name="basai_ai_justification" value="<?php echo esc_attr( $just ); ?>">
                            <input type="hidden" id="basai-template-id-input" name="basai_template_id" value="<?php echo esc_attr( $tmpl ); ?>">
                            <input type="hidden" id="basai-reviewed-type-input" name="basai_reviewed_type" value="<?php echo esc_attr( $reviewed ); ?>">

                            <div id="basai-loading" class="basai-loading-overlay">
                                <div class="basai-spinner-box">
                                    <span class="spinner is-active"></span>
                                    <span>Generating Schema...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
        <?php
    }

    public function save_meta_data( int $post_id ): void {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( (string) $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST[ self::META_KEY_LIVE ] ) ) {
            $just = sanitize_text_field( (string) ( $_POST['basai_ai_justification'] ?? '' ) );
            $tmpl = sanitize_text_field( (string) ( $_POST['basai_template_id'] ?? 'Auto' ) );
            $rev  = sanitize_text_field( (string) ( $_POST['basai_reviewed_type'] ?? '' ) );

            // Note: We don't have access to fresh missing_info here (that comes from AJAX generation).
            // We just save the JSON content and preserve existing missing info if not updating it via AJAX.
            // However, save_schema expects 6 args now.
            // In manual save context, we likely don't have new missing info, so we can pass empty array
            // OR we should retrieve existing?
            // Actually, manual save implies user edited JSON. Missing info from AI is stale/irrelevant if user manually fixed it.
            // Ideally, we'd clear it or keep it. Let's look at how AJAX handles it.

            // Wait, this method is for standard WP Post Save.
            // If the user manually edits the JSON in the textarea and hits "Update" on the post, this runs.
            // We should probably preserve the existing missing info unless we have a way to re-evaluate it?
            // Or just pass empty array to clear it since manual edit might have fixed it?
            // Core::save_schema clears it if empty array is passed? No, check logic.
            // Logic: if (!empty($missing_info)) update; else delete.

            // So if I pass [], it deletes it. That seems correct for a manual save (AI warnings are cleared until next generation).
            Core::save_schema( $post_id, (string) wp_unslash( (string) $_POST[ self::META_KEY_LIVE ] ), $tmpl, $just, '', [] );

            if ( '' !== $tmpl ) {
                update_post_meta( $post_id, self::META_KEY_TEMPLATE_ID, $tmpl );
            }
            update_post_meta( $post_id, self::META_KEY_REVIEWED_TYPE, $rev );
        }

    }

    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_style( 'basai-css', BASAI_URL . 'assets/css/admin-style.css', [], BASAI_VERSION );
        wp_enqueue_script( 'basai-js', BASAI_URL . 'assets/js/admin-script.js', [ 'jquery' ], BASAI_VERSION, true );

        wp_localize_script(
            'basai-js',
            'basaiData',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'basai_ajax_nonce' ),
            ]
        );
    }

    public function handle_ajax_generate_now(): void {
        check_ajax_referer( 'basai_ajax_nonce', 'nonce' );

        $post_id      = absint( $_POST['post_id'] ?? 0 );
        $selected_type = sanitize_text_field( (string) ( $_POST['selected_type'] ?? 'Auto' ) );
        $selected_reviewed = sanitize_text_field( (string) ( $_POST['selected_reviewed_type'] ?? '' ) );
        $should_save  = (int) ( $_POST['save'] ?? 0 ) === 1;

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found.' ] );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $api     = new OpenAI_Handler();
        $builder = new Schema_Builder();

        $analysis = $api->analyze_post( $post, $selected_type ?: 'Auto', $selected_reviewed );
        if ( is_wp_error( $analysis ) ) {
            wp_send_json_error( [ 'message' => $analysis->get_error_message() ] );
        }

        if ( '' !== $selected_reviewed ) {
            $analysis['details']['reviewed_type'] = $selected_reviewed;
        }

        $final_type    = (string) ( $analysis['type'] ?? 'BlogPosting' );
        $justification = (string) ( $analysis['justification'] ?? '' );
        $summary       = (string) ( $analysis['summary'] ?? '' );
        $reviewed_type = (string) ( $analysis['details']['reviewed_type'] ?? '' );
        $missing_info  = is_array( $analysis['missing_info'] ?? null ) ? $analysis['missing_info'] : [];

        $schema_arr = $builder->build_complete_schema( $post, $analysis, $final_type );
        $json_str   = wp_json_encode( $schema_arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        $supported = Schema_Builder::get_supported_types();
        $label     = $supported[ $final_type ] ?? $final_type;

        $saved = false;
        if ( $should_save ) {
            $saved = Core::save_schema( $post_id, $json_str, $final_type, $justification, $summary, $missing_info );
            if ( '' !== $reviewed_type ) {
                update_post_meta( $post_id, self::META_KEY_REVIEWED_TYPE, $reviewed_type );
            }
        }

        wp_send_json_success(
            [
                'schema'        => $json_str,
                'justification' => $justification,
                'summary'       => $summary,
                'generated_type'=> $final_type,
                'generated_label'=> $label,
                'reviewed_type' => $reviewed_type,
                'saved'         => $saved,
                'missing_info'  => $missing_info, // Pass back to JS if needed for immediate UI update
            ]
        );
    }

    public function handle_ajax_validate_schema(): void {
        check_ajax_referer( 'basai_ajax_nonce', 'nonce' );

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $json = (string) wp_unslash( (string) ( $_POST['json'] ?? '' ) );
        if ( '' === trim( $json ) ) {
            wp_send_json_error( [ 'message' => 'Empty JSON provided.' ] );
        }

        $report = Schema_Validator::validate_json(
            $json,
            [
                'site_url' => home_url( '/' ),
            ]
        );

        wp_send_json_success( $report );
    }
}
