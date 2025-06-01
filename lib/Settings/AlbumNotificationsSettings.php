<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\Template;

class AlbumNotificationsSettings implements ISettings {
  private $userSession;

  public function __construct(IUserSession $userSession) {
    $this->userSession = $userSession;
  }

  public function getForm() {
    $user = $this->userSession->getUser();
    $displayName = $user ? $user->getDisplayName() : 'Unknown';
    $template = new Template('album_notifications', 'settings');
    $template->assign('user', $displayName);
    return $template;
  }

  public function getSection() {
    return 'album_notifications';
  }

  public function getPriority() {
    return 10;
  }
}