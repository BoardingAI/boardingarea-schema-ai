jQuery(document).ready(function ($) {
  const nonce = window.basaiBatch.nonce;
  const ajaxUrl = window.basaiBatch.ajaxUrl;

  function log(msg, color = '#ddd') {
    $('#basai-log').append(`<div style="color:${color}">[${new Date().toLocaleTimeString()}] ${msg}</div>`);
    const el = $('#basai-log')[0];
    el.scrollTop = el.scrollHeight;
  }

  function refreshStats() {
    $.ajax({
      url: ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: { action: 'basai_queue_stats', nonce: nonce },
      success: function (res) {
        if (!res.success) return;
        const c = res.data.counts;
        $('#basai-stat-pending').text(c.pending);
        $('#basai-stat-running').text(c.running);
        $('#basai-stat-complete').text(c.complete);
        $('#basai-stat-failed').text(c.failed);

        if (res.data.last_failed) {
          $('#basai-last-failed').show();
          $('#basai-last-failed-text').text(`Post ${res.data.last_failed.post_id}: ${res.data.last_failed.last_error} (${res.data.last_failed.updated_at})`);
        }
      }
    });
  }

  function queue(actionName) {
    log('Queueing...', '#0ff');
    $.ajax({
      url: ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: { action: actionName, nonce: nonce },
      success: function (res) {
        if (res.success) {
          log(res.data.message, '#0f0');
          refreshStats();
        } else {
          log((res.data && res.data.message) ? res.data.message : 'Queue failed', '#f00');
        }
      },
      error: function () {
        log('Server error while queueing', '#f00');
      }
    });
  }

  $('#basai-queue-missing').on('click', function () {
    queue('basai_batch_queue_missing');
  });

  $('#basai-queue-all').on('click', function () {
    queue('basai_batch_queue_all');
  });

  $('#basai-run-now').on('click', function () {
    log('Running worker now (2 jobs)...', '#0ff');
    $.ajax({
      url: ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: { action: 'basai_queue_run_now', nonce: nonce, max: 2 },
      success: function (res) {
        if (res.success) {
          log(res.data.message, '#0f0');
          refreshStats();
        } else {
          log('Worker run failed', '#f00');
        }
      },
      error: function () {
        log('Server error while running worker', '#f00');
      }
    });
  });

  refreshStats();
  setInterval(refreshStats, 5000);
});
