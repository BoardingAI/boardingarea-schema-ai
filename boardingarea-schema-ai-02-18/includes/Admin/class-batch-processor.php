<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Admin;

use BoardingArea\SchemaAI\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Batch_Processor {

	public const SLUG = 'basai-batch';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );

		add_action( 'wp_ajax_basai_batch_queue_missing', [ $this, 'ajax_queue_missing' ] );
		add_action( 'wp_ajax_basai_batch_queue_all', [ $this, 'ajax_queue_all' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'options-general.php',
			'BoardingArea Schema AI Batch',
			'Schema AI Batch',
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		?>
		<div class="wrap">
			<h1>BoardingArea Schema AI — Batch Queue</h1>
			<p>This screen queues async generation jobs. Processing runs via WP-Cron every ~2 minutes (or click “Run Worker Now”).</p>

			<div class="card" style="max-width: 900px;">
				<h2>Queue Controls</h2>

				<p>
					<button id="basai-queue-missing" class="button button-primary">Queue Missing Only</button>
					<button id="basai-queue-all" class="button">Queue ALL Published Posts</button>
					<button id="basai-run-now" class="button button-secondary">Run Worker Now (2 jobs)</button>
				</p>

				<hr />

				<h3>Queue Status</h3>
				<ul>
					<li>Pending: <strong id="basai-stat-pending">0</strong></li>
					<li>Running: <strong id="basai-stat-running">0</strong></li>
					<li>Complete: <strong id="basai-stat-complete">0</strong></li>
					<li>Failed: <strong id="basai-stat-failed">0</strong></li>
				</ul>

				<div id="basai-last-failed" style="display:none;" class="notice notice-error inline">
					<p><strong>Last Failed:</strong> <span id="basai-last-failed-text"></span></p>
				</div>

				<div id="basai-log" style="background:#111;color:#ddd;height:260px;overflow:auto;padding:10px;font-family:monospace;"></div>
			</div>
		</div>
		<script>
			window.basaiBatch = window.basaiBatch || {};
			window.basaiBatch.nonce = "<?php echo esc_js( wp_create_nonce( 'basai_batch_nonce' ) ); ?>";
			window.basaiBatch.ajaxUrl = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";
		</script>
		<?php
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'basai-batch', BASAI_URL . 'assets/js/batch-script.js', [ 'jquery' ], BASAI_VERSION, true );
	}

	public function ajax_queue_missing(): void {
		check_ajax_referer( 'basai_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$ids = get_posts(
			[
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'     => Meta_Box::META_KEY_LIVE,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$queued = 0;
		$s = new Scheduler();
		foreach ( $ids as $id ) {
			if ( $s->enqueue( (int) $id, 'Auto' ) ) {
				$queued++;
			}
		}

		wp_send_json_success( [ 'message' => "Queued {$queued} posts (missing only).", 'queued' => $queued ] );
	}

	public function ajax_queue_all(): void {
		check_ajax_referer( 'basai_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$ids = get_posts(
			[
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
			]
		);

		$queued = 0;
		$s = new Scheduler();
		foreach ( $ids as $id ) {
			if ( $s->enqueue( (int) $id, 'Auto' ) ) {
				$queued++;
			}
		}

		wp_send_json_success( [ 'message' => "Queued {$queued} posts (first 500).", 'queued' => $queued ] );
	}
}
