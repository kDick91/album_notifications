<?php
use OCP\Util;
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
                    <th><?php p($l->t('Source')); ?></th>
                    <th><?php p($l->t('Daily Notification')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($albums as $album): ?>
                    <tr>
                        <td><?php p($album['name'] ?? 'Unknown Album'); ?></td>
                        <td><?php p($album['source'] ?? 'Unknown'); ?></td>
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
        
        <div class="settings-section" style="margin-top: 30px;">
            <h3><?php p($l->t('Test Email Notifications')); ?></h3>
            <p><?php p($l->t('Send a test email to verify your notification setup is working correctly.')); ?></p>
            <button id="send-test-email" class="button primary">
                <?php p($l->t('Send Test Email')); ?>
            </button>
            <div id="test-email-status" style="margin-top: 10px;"></div>
        </div>
        
        <div class="settings-hint">
            <p><?php p($l->t('Changes are saved automatically when you check/uncheck albums.')); ?></p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.album-checkbox');
    const testEmailButton = document.getElementById('send-test-email');
    const testEmailStatus = document.getElementById('test-email-status');
    
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
    
    function sendTestEmail() {
        testEmailButton.disabled = true;
        testEmailButton.textContent = 'Sending...';
        testEmailStatus.innerHTML = '';
        
        fetch(OC.generateUrl('/apps/album_notifications/test-email'), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            testEmailButton.disabled = false;
            testEmailButton.textContent = 'Send Test Email';
            
            if (data.status === 'success') {
                testEmailStatus.innerHTML = '<span style="color: green;">✅ ' + data.message + '</span>';
                OC.Notification.showTemporary('Test email sent successfully!');
            } else {
                testEmailStatus.innerHTML = '<span style="color: red;">❌ ' + data.message + '</span>';
                OC.Notification.showTemporary('Failed to send test email: ' + data.message);
            }
        })
        .catch(error => {
            testEmailButton.disabled = false;
            testEmailButton.textContent = 'Send Test Email';
            testEmailStatus.innerHTML = '<span style="color: red;">❌ Error sending test email</span>';
            console.error('Error sending test email:', error);
            OC.Notification.showTemporary('Error sending test email');
        });
    }
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', saveSettings);
    });
    
    if (testEmailButton) {
        testEmailButton.addEventListener('click', sendTestEmail);
    }
});
</script>