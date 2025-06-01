<?php
use OCP\Util;
?>
<div class="section">
    <h2><?php p($l->t('Album Notifications')); ?></h2>
    <p><?php p($l->t('Hello %s', [$user])); ?></p>
    
    <!-- Debug Information -->
    <details style="margin-bottom: 20px;">
        <summary><strong>Debug Information (click to expand)</strong></summary>
        <div style="background: #f5f5f5; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 12px;">
            <p><strong>User ID:</strong> <?php p($debug_info['user_id']); ?></p>
            <p><strong>Photos App Enabled:</strong> <?php p($debug_info['photos_enabled'] ? 'Yes' : 'No'); ?></p>
            <p><strong>Memories App Enabled:</strong> <?php p($debug_info['memories_enabled'] ? 'Yes' : 'No'); ?></p>
            
            <h4>Existing Tables:</h4>
            <?php if (empty($debug_info['existing_tables'])): ?>
                <p>No relevant tables found</p>
            <?php else: ?>
                <?php foreach ($debug_info['existing_tables'] as $table): ?>
                    <details style="margin: 5px 0;">
                        <summary><strong><?php p($table); ?></strong></summary>
                        <div style="margin-left: 20px;">
                            <p><strong>Columns:</strong> <?php p(implode(', ', $debug_info['table_structure'][$table])); ?></p>
                            <p><strong>Sample Data:</strong></p>
                            <pre><?php p(json_encode($debug_info['table_sample_data'][$table], JSON_PRETTY_PRINT)); ?></pre>
                        </div>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>
    
    <?php if (empty($albums)): ?>
        <div class="notecard warning">
            <h3><?php p($l->t('No albums found')); ?></h3>
            <p><?php p($l->t('Debug information above shows what tables exist and their content. Check your Nextcloud logs for more details.')); ?></p>
        </div>
    <?php else: ?>
        <p><?php p($l->t('Select which albums you want to receive daily notifications for:')); ?></p>
        <table class="grid">
            <thead>
                <tr>
                    <th><?php p($l->t('Album Name')); ?></th>
                    <th><?php p($l->t('Source')); ?></th>
                    <th><?php p($l->t('Daily Notification')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($albums as $album): ?>
                    <tr>
                        <td><?php p($album['name'] ?? 'Unknown Album'); ?></td>
                        <td><?php p($album['source'] ?? 'unknown'); ?></td>
                        <td>
                            <input type="checkbox" 
                                   class="album-checkbox" 
                                   value="<?php p($album['id']); ?>"
                                   data-album-name="<?php p($album['name']); ?>"
                                   <?php if (in_array($album['id'], $selected_albums)) echo 'checked'; ?> />
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="settings-hint">
            <p><?php p($l->t('Changes are saved automatically when you check/uncheck albums.')); ?></p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.album-checkbox');
    
    function saveSettings() {
        const selected = Array.from(checkboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
        
        // Save to Nextcloud user config
        const data = new FormData();
        data.append('selected_albums', JSON.stringify(selected));
        
        fetch(OC.generateUrl('/apps/album_notifications/settings'), {
            method: 'POST',
            body: data,
            headers: {
                'requesttoken': OC.requestToken
            }
        }).then(response => {
            if (response.ok) {
                OC.Notification.showTemporary('Settings saved successfully');
            } else {
                OC.Notification.showTemporary('Failed to save settings');
            }
        }).catch(error => {
            console.error('Error saving settings:', error);
            OC.Notification.showTemporary('Error saving settings');
        });
    }
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', saveSettings);
    });
});
</script>