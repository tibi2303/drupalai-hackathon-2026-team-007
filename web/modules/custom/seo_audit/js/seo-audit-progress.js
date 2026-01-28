(function (Drupal, drupalSettings, once) {
  'use strict';

  const PHASE_PROGRESS = {
    queued: 10,
    processing_deterministic: 40,
    processing_ai: 70,
    completed: 100,
  };

  const POLL_INTERVAL = 4000;
  const MAX_RETRIES = 3;

  Drupal.behaviors.seoAuditProgress = {
    attach(context) {
      once('seo-audit-progress', '.seo-audit-progress', context).forEach(
        function (container) {
          const statusUrl = drupalSettings.seoAudit && drupalSettings.seoAudit.statusUrl;
          if (!statusUrl) {
            return;
          }

          const progressBar = new Drupal.ProgressBar('seo-audit-progress-bar');
          progressBar.setProgress(PHASE_PROGRESS.queued, Drupal.t('Queued for processing…'));
          container.prepend(progressBar.element);

          let retries = 0;
          let timer = null;

          function poll() {
            fetch(statusUrl, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            })
              .then(function (response) {
                if (!response.ok) {
                  throw new Error('HTTP ' + response.status);
                }
                return response.json();
              })
              .then(function (data) {
                retries = 0;
                const phase = data.status || 'queued';
                const pct = PHASE_PROGRESS[phase] || PHASE_PROGRESS.queued;
                const label = data.status_label || phase;

                if (phase === 'failed') {
                  clearInterval(timer);
                  progressBar.displayError(
                    Drupal.t('Audit failed: @error', { '@error': data.error_message || Drupal.t('Unknown error') })
                  );
                  return;
                }

                progressBar.setProgress(pct, label);

                if (phase === 'completed') {
                  clearInterval(timer);
                  setTimeout(function () {
                    window.location.reload();
                  }, 1000);
                }
              })
              .catch(function () {
                retries++;
                if (retries >= MAX_RETRIES) {
                  clearInterval(timer);
                  progressBar.displayError(
                    Drupal.t('Unable to reach the server. Please refresh the page to try again.')
                  );
                }
              });
          }

          poll();
          timer = setInterval(poll, POLL_INTERVAL);
        }
      );
    },
  };
})(Drupal, drupalSettings, once);
