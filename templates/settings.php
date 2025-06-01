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
    
    console.log('Album Notifications settings loaded');
    console.log('Test email button:', testEmailButton);
    
    function saveSettings() {
        const selected = Array.from(checkboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
        
        console.log('Saving settings for albums:', selected);
        
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
            console.log('Settings save response:', response);
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
        console.log('sendTestEmail function called');
        
        testEmailButton.disabled = true;
        testEmailButton.textContent = 'Sending...';
        testEmailStatus.innerHTML = '';
        
        const url = OC.generateUrl('/apps/album_notifications/test-email');
        console.log('Sending test email to URL:', url);
        
        fetch(url, {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            console.log('Test email response status:', response.status);
            console.log('Test email response headers:', response.headers);
            return response.json();
        })
        .then(data => {
            console.log('Test email response data:', data);
            
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
            console.error('Error sending test email:', error);
            testEmailButton.disabled = false;
            testEmailButton.textContent = 'Send Test Email';
            testEmailStatus.innerHTML = '<span style="color: red;">❌ Error sending test email</span>';
            OC.Notification.showTemporary('Error sending test email');
        });
    }
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', saveSettings);
    });
    
    if (testEmailButton) {
        console.log('Adding click listener to test email button');
        testEmailButton.addEventListener('click', function(e) {
            console.log('Test email button clicked');
            e.preventDefault();
            sendTestEmail();
        });
    } else {
        console.error('Test email button not found!');
    }
});
</script>