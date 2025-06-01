<?php
use OCP\Util;
Util::addScript('album_notifications', 'settings');
?>
<div class="section">
    <h2><?php p($l->t('Album Notifications')); ?></h2>
    <p><?php p($l->t('Hello %s', [$user])); ?></p>
    
    <?php if (empty($albums)): ?>
        <div class="notecard warning">
            <h3><?php p($l->t('No albums found')); ?></h3>
            <p><?php p($l->t('Make sure you have:')); ?></p>
            <ul>
                <li><?php p($l->t('Photos or Memories app installed and enabled')); ?></li>
                <li><?php p($l->t('Created at least one album')); ?></li>
                <li><?php p($l->t('Proper permissions to access albums')); ?></li>
            </ul>
        </div>
    <?php else: ?>
        <p><?php p($l->t('Select which albums you want to receive daily notifications for:')); ?></p>
        <table class="grid">
            <thead>
                <tr>
                    <th><?php p($l->t('Album Name')); ?></th>
                    <th><?php p($l->t('Daily Notification')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($albums as $album): ?>
                    <tr>
                        <td><?php p($album['name'] ?? 'Unknown Album'); ?></td>
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