<?php
use OCP\Util;
?>
<p>Hello <?php p($user); ?></p>
<?php if (empty($albums)): ?>
    <p>No albums found. Ensure the Photos app is enabled and you have albums in Memories.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Album Name</th>
                <th>Daily Notification</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($albums as $album): ?>
                <tr>
                    <td><?php p($album['name'] ?? 'Unknown Album'); ?></td>
                    <td>
                        <input type="checkbox" class="album-checkbox" value="<?php p($album['id']); ?>"
                            <?php if (in_array($album['id'], $selected_albums)) echo 'checked'; ?> />
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <input type="hidden" name="selected_albums" id="selected_albums" value="<?php p(json_encode($selected_albums)); ?>" />
    <script>
        $(document).ready(function() {
            const $checkboxes = $('.album-checkbox');
            const $selectedInput = $('#selected_albums');
            
            function updateSelected() {
                const selected = $checkboxes.filter(':checked').map(function() { return this.value; }).get();
                $selectedInput.val(JSON.stringify(selected));
            }
            
            $checkboxes.on('change', updateSelected);
            
            // Initial update
            updateSelected();
        });
    </script>
<?php endif; ?>