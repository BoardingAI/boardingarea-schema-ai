<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI;

use wpdb;
use WP_Error;
use BoardingArea\SchemaAI\Admin\Settings;
use BoardingArea\SchemaAI\Admin\Meta_Box;
use BoardingArea\SchemaAI\Api\OpenAI_Handler;
use BoardingArea\SchemaAI\Builder\Schema_Builder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Scheduler {

    public const TABLE_SLUG = 'basai_jobs';
    public const CRON_HOOK  = 'basai_run_queue';
    public const LOCK_KEY   = 'basai_queue_lock';
    public const META_KEY_CONTENT_HASH = '_basai_content_hash';

    public function init(): void {
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_queue' ] );
        add_action( 'save_post', [ $this, 'maybe_enqueue_on_save' ], 20, 3 );

        add_action( 'wp_ajax_basai_queue_stats', [ $this, 'ajax_queue_stats' ] );
        add_action( 'wp_ajax_basai_queue_run_now', [ $this, 'ajax_queue_run_now' ] );
        add_action( 'wp_ajax_basai_queue_enqueue', [ $this, 'ajax_queue_enqueue' ] );
    }

    public function activate(): void {
        $this->maybe_create_table();
        $this->ensure_cron_scheduled();
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    public function add_cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['basai_two_minutes'] ) ) {
            $schedules['basai_two_minutes'] = [
                'interval' => 120,
                'display'  => __( 'BoardingArea Schema AI: Every 2 Minutes', 'boardingarea-schema-ai' ),
            ];
        }
        return $schedules;
    }

    private function ensure_cron_scheduled(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'basai_two_minutes', self::CRON_HOOK );
        }
    }

    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    private function maybe_create_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = $this->table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            task VARCHAR(50) NOT NULL DEFAULT 'generate',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
            content_hash CHAR(32) NOT NULL DEFAULT '',
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY status (status),
            KEY content_hash (content_hash)
        ) {$charset};";

        dbDelta( $sql );
    }

    private function acquire_lock(): bool {
        if ( get_transient( self::LOCK_KEY ) ) {
            return false;
        }
        set_transient( self::LOCK_KEY, 1, 90 );
        return true;
    }

    private function release_lock(): void {
        delete_transient( self::LOCK_KEY );
    }

    private function compute_hash( int $post_id ): string {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }
        return md5( (string) $post->post_title . '||' . (string) $post->post_content . '||' . (string) $post->post_modified_gmt );
    }

    public function enqueue( int $post_id, string $forced_template_id = 'Auto' ): bool {
        global $wpdb;

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            return false;
        }

        $hash = $this->compute_hash( $post_id );
        if ( '' === $hash ) {
            return false;
        }

        $table = $this->table_name();

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE post_id=%d
                   AND content_hash=%s
                   AND status IN ('pending','running')
                 LIMIT 1",
                $post_id,
                $hash
            )
        );

        if ( $existing ) {
            return true;
        }

        $now = current_time( 'mysql' );

        $ok = (bool) $wpdb->insert(
            $table,
            [
                'post_id'       => $post_id,
                'task'          => 'generate',
                'status'        => 'pending',
                'attempts'      => 0,
                'max_attempts'  => 3,
                'content_hash'  => $hash,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );

        update_post_meta( $post_id, self::META_KEY_CONTENT_HASH, $hash );

        if ( 'Auto' !== $forced_template_id && '' !== $forced_template_id ) {
            update_post_meta( $post_id, Meta_Box::META_KEY_TEMPLATE_ID, sanitize_text_field( $forced_template_id ) );
        }

        return $ok;
    }

    public function maybe_enqueue_on_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
        if ( ! in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) ) return;

        $global_auto = (bool) get_option( Settings::OPTION_AUTO_ON_SAVE, false );
        if ( ! $global_auto ) return;

        $api_key = Settings::get_effective_api_key();
        if ( '' === $api_key ) return;

        $new_hash  = $this->compute_hash( $post_id );
        $last_hash = (string) get_post_meta( $post_id, self::META_KEY_CONTENT_HASH, true );
        if ( '' !== $new_hash && $new_hash === $last_hash ) return;

        $template = (string) get_post_meta( $post_id, Meta_Box::META_KEY_TEMPLATE_ID, true );
        $enqueued = $this->enqueue( $post_id, $template ?: 'Auto' );

        $this->ensure_cron_scheduled();

        $run_now = (bool) apply_filters( 'basai_run_queue_on_save', true, $post_id, $template );
        if ( $enqueued && $run_now ) {
            $this->run_queue( 1 );
        }
    }

	public function run_queue( int $max_jobs = 2 ): void {
		global $wpdb;

		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			$table = $this->table_name();

			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE status='pending'
					 ORDER BY id ASC
					 LIMIT %d",
					$max_jobs
				),
				ARRAY_A
			);

			if ( empty( $jobs ) ) {
				return;
			}

			foreach ( $jobs as $job ) {
				$this->process_job( $job );
			}
		} finally {
			$this->release_lock();
		}
	}

	private function process_job( array $job ): void {
		global $wpdb;

		$table  = $this->table_name();
		$job_id = (int) $job['id'];
		$post_id = (int) $job['post_id'];

		$now = current_time( 'mysql' );

		$wpdb->update(
			$table,
			[
				'status'     => 'running',
				'updated_at' => $now,
				'started_at' => $now,
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->fail_job( $job_id, $job, 'Post not found.' );
			return;
		}

		$forced_template = (string) get_post_meta( $post_id, Meta_Box::META_KEY_TEMPLATE_ID, true );
		if ( '' === $forced_template ) {
			$forced_template = 'Auto';
		}

		$forced_reviewed = (string) get_post_meta( $post_id, Meta_Box::META_KEY_REVIEWED_TYPE, true );

		$api     = new OpenAI_Handler();
		$builder = new Schema_Builder();

		$analysis = $api->analyze_post( $post, $forced_template, $forced_reviewed );
		if ( is_wp_error( $analysis ) ) {
			$this->fail_job( $job_id, $job, $analysis->get_error_message() );
			return;
		}

		if ( '' !== $forced_reviewed ) {
			$analysis['details']['reviewed_type'] = $forced_reviewed;
		}

		$final_type     = (string) ( $analysis['type'] ?? 'BlogPosting' );
		$justification  = (string) ( $analysis['justification'] ?? '' );
		$summary        = (string) ( $analysis['summary'] ?? '' );

		$schema_arr = $builder->build_complete_schema( $post, $analysis, $final_type );
		$json       = wp_json_encode( $schema_arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$ok = Core::save_schema( $post_id, (string) $json, $final_type, $justification, $summary );

		if ( ! $ok ) {
			$this->fail_job( $job_id, $job, 'Generated JSON failed to validate locally.' );
			return;
		}

		$wpdb->update(
			$table,
			[
				'status'       => 'complete',
				'updated_at'   => current_time( 'mysql' ),
				'completed_at' => current_time( 'mysql' ),
				'last_error'   => null,
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		update_post_meta( $post_id, self::META_KEY_CONTENT_HASH, (string) $job['content_hash'] );
	}

	private function fail_job( int $job_id, array $job, string $error ): void {
		global $wpdb;

		$table     = $this->table_name();
		$attempts  = (int) $job['attempts'] + 1;
		$max       = (int) $job['max_attempts'];
		$new_status = ( $attempts >= $max ) ? 'failed' : 'pending';

		$wpdb->update(
			$table,
			[
				'status'     => $new_status,
				'attempts'   => $attempts,
				'last_error' => wp_strip_all_tags( $error ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $job_id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		$post_id = (int) $job['post_id'];
		update_post_meta( $post_id, Meta_Box::META_KEY_LAST_ERROR, sanitize_text_field( $error ) );
	}

	public function ajax_queue_enqueue(): void {
		check_ajax_referer( 'basai_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$type    = sanitize_text_field( $_POST['template_id'] ?? 'Auto' );

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => 'Missing post_id.' ] );
		}

		$ok = $this->enqueue( $post_id, $type ?: 'Auto' );

		if ( $ok ) {
			$this->ensure_cron_scheduled();
			wp_send_json_success( [ 'message' => "Queued post {$post_id}." ] );
		}

		wp_send_json_error( [ 'message' => 'Failed to enqueue.' ] );
	}

	public function ajax_queue_stats(): void {
		check_ajax_referer( 'basai_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		global $wpdb;
		$table = $this->table_name();

		$counts = [
			'pending'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='pending'" ),
			'running'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='running'" ),
			'complete' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='complete'" ),
			'failed'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" ),
		];

		$last_failed = $wpdb->get_row(
			"SELECT post_id, last_error, updated_at FROM {$table} WHERE status='failed' ORDER BY updated_at DESC LIMIT 1",
			ARRAY_A
		);

		wp_send_json_success(
			[
				'counts'      => $counts,
				'last_failed' => $last_failed ?: null,
			]
		);
	}

	public function ajax_queue_run_now(): void {
		check_ajax_referer( 'basai_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$max = absint( $_POST['max'] ?? 2 );
		$max = max( 1, min( 5, $max ) );

		$this->run_queue( $max );

		wp_send_json_success( [ 'message' => "Processed up to {$max} jobs." ] );
	}
}
