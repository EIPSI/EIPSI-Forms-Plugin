<?php
if (!defined('ABSPATH')) { exit; }
if (!EIPSI_Admin_Authorization_Policy::allows('schema.manage')) { return; }
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/schema/class-schema-diagnostic.php';
$report = EIPSI_Schema_Diagnostic::report();
$ok = $report['status'] === 'ok';
$migrations = $report['migrations'];
?>
<div class="schema-status-tab-container" id="eipsi-schema-status">
    <h2><span class="dashicons dashicons-database" aria-hidden="true"></span> <?php echo esc_html(eipsi_admin_translate('Database status')); ?></h2>
    <p><?php echo esc_html(eipsi_admin_translate('Check the table structure and plugin migration version.')); ?></p>
    <div class="eipsi-schema-summary <?php echo $ok ? 'is-ok' : 'needs-attention'; ?>" role="status">
        <strong><?php echo $ok ? eipsi_admin_translate('Structure checked without issues within the stated scope') : eipsi_admin_translate('The structure needs attention'); ?></strong>
        <p><?php echo esc_html(eipsi_admin_format('Tables checked: %1$d · with issues: %2$d',$report['summary']['total_tables'],$report['summary']['tables_with_issues'])); ?></p>
        <p><?php echo esc_html(eipsi_admin_translate('Migrations:')); ?> <strong><?php echo esc_html($migrations['installed'] . ' / ' . $migrations['latest']); ?></strong>
        <?php if ($migrations['installed'] > $migrations['latest']): ?> · <?php echo esc_html(eipsi_admin_translate('The database belongs to a later plugin version.')); ?>
        <?php elseif ($migrations['pending']): ?> · <?php echo esc_html(eipsi_admin_plural('%d pending migration',(int)$migrations['pending'])); ?>
        <?php else: ?> · <?php echo esc_html(eipsi_admin_translate('Up to date.')); ?>
        <?php endif; ?></p>
    </div>
    <p class="eipsi-schema-actions">
        <button type="button" class="button button-primary" id="refresh-schema"><?php echo esc_html(eipsi_admin_translate('Check again')); ?></button>
        <button type="button" class="button" id="export-schema-report"><?php echo esc_html(eipsi_admin_translate('Download technical report')); ?></button>
    </p>
    <p id="schema-report-feedback" role="status" aria-live="polite"></p>
    <?php if (!$ok): ?>
    <h3><?php echo esc_html(eipsi_admin_translate('Issues found')); ?></h3>
    <ul class="eipsi-schema-problems">
        <?php foreach ($report['tables'] as $slug => $table): if (!$table['issues']) { continue; } ?>
        <li><strong><?php echo esc_html($slug); ?></strong><ul>
            <?php foreach ($table['issues'] as $issue): ?><li><?php echo esc_html(EIPSI_Schema_Diagnostic::describe($issue)); ?></li><?php endforeach; ?>
        </ul></li>
        <?php endforeach; ?>
    </ul>
    <p><?php echo esc_html(eipsi_admin_translate('Download the report to review differences with technical support. Pending migrations require reviewing the plugin update; structural differences require reviewing the affected module installer.')); ?></p>
    <?php endif; ?>
    <details class="eipsi-schema-scope"><summary><?php echo esc_html(eipsi_admin_translate('What is checked')); ?></summary>
        <p><?php echo esc_html(eipsi_admin_translate('Central registry tables are checked for columns, types, indexes and declared storage engine. The three Native assessment tables are checked for existence and InnoDB engine according to their runtime availability check.')); ?></p>
        <p><?php echo esc_html(eipsi_admin_translate('This diagnostic does not check response content or research data integrity.')); ?></p>
        <p><?php echo esc_html(eipsi_admin_format('Checked at: %s',$report['generated_at'])); ?></p>
    </details>
</div>
<style>
#eipsi-schema-status{max-width:960px;padding:20px}
#eipsi-schema-status h2{display:flex;gap:8px;align-items:center}
#eipsi-schema-status .eipsi-schema-summary{padding:18px 22px;border-radius:8px;border-left:4px solid #15803d;background:#f0f9f3;margin:20px 0}
#eipsi-schema-status .needs-attention{border-color:#b45309;background:#fff8eb}
#eipsi-schema-status .eipsi-schema-summary p:last-child{margin-bottom:0}
#eipsi-schema-status .eipsi-schema-actions{display:flex;gap:10px;flex-wrap:wrap}
#eipsi-schema-status .eipsi-schema-problems>li{padding:14px;background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow-wrap:anywhere}
#eipsi-schema-status .eipsi-schema-problems ul{list-style:disc;padding-left:22px;margin-top:8px}
#eipsi-schema-status .eipsi-schema-scope{margin-top:24px;color:#50575e}
#eipsi-schema-status summary{cursor:pointer;font-weight:600}
</style>
<script>
jQuery(function ($) {
    $('#refresh-schema').on('click', function () { this.disabled = true; window.location.reload(); });
    $('#export-schema-report').on('click', function () {
        var button = $(this), feedback = $('#schema-report-feedback');
        button.prop('disabled', true);
        feedback.text(<?php echo wp_json_encode(eipsi_admin_translate("Preparing report…")); ?>);
        $.ajax({url: ajaxurl, type: 'POST', data: {
            action: 'eipsi_export_schema_report', nonce: <?php echo wp_json_encode(wp_create_nonce('eipsi_admin_nonce')); ?>
        }}).done(function (response) {
            if (!response.success) { feedback.text(<?php echo wp_json_encode(eipsi_admin_translate("Could not generate the technical report.")); ?>); return; }
            var url = URL.createObjectURL(new Blob([JSON.stringify(response.data, null, 2)], {type: 'application/json'}));
            var link = document.createElement('a');
            link.href = url; link.download = 'eipsi-schema-report-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(link); link.click(); link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            feedback.text(<?php echo wp_json_encode(eipsi_admin_translate("Technical report generated.")); ?>);
        }).fail(function () { feedback.text(<?php echo wp_json_encode(eipsi_admin_translate("Could not generate the technical report.")); ?>); })
          .always(function () { button.prop('disabled', false); });
    });
});
</script>
