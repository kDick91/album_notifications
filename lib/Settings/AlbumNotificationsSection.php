<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AlbumNotificationsSection implements IIconSection {
  private $l10n;
  private $urlGenerator;

  public function __construct(IL10N $l10n, IURLGenerator $urlGenerator) {
    $this->l10n = $l10n;
    $this->urlGenerator = $urlGenerator;
  }

  public function getID() {
    return 'album_notifications';
  }

  public function getName() {
    return $this->l10n->t('Album Notifications');
  }

  public function getPriority() {
    return 50;
  }

  public function getIcon() {
    return $this->urlGenerator->imagePath('album_notifications', 'app.svg');
  }
}