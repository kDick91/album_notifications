<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Http\Client\IClientService;
use OCP\ILogger;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $clientService;
    private $userId;
    private $logger;

    public function __construct(IUserSession $userSession, IConfig $config, IURLGenerator $urlGenerator, IClientService $clientService, ILogger $logger) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->clientService = $clientService;
        $this->userId = $userSession->getUser()->getUID();
        $this->logger = $logger;
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();

        // Fetch albums from Photos app
        $client = $this->clientService->newClient();
        $myAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.api.listAlbums', ['type' => 'my']);
        $sharedAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.api.listAlbums', ['type' => 'shared']);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->userSession->getUser()->getUID(),
            ],
        ];

        try {
            $myAlbumsResponse = $client->get($myAlbumsUrl, $options);
            $myAlbumsBody = $myAlbumsResponse->getBody();
            $this->logger->log(\Psr\Log\LogLevel::DEBUG, 'My Albums Response: ' . $myAlbumsBody, ['app' => 'album_notifications']);
            $myAlbums = json_decode($myAlbumsBody, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->log(\Psr\Log\LogLevel::ERROR, 'Failed to fetch my albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
            $myAlbums = [];
        }

        try {
            $sharedAlbumsResponse = $client->get($sharedAlbumsUrl, $options);
            $sharedAlbumsBody = $sharedAlbumsResponse->getBody();
            $this->logger->log(\Psr\Log\LogLevel::DEBUG, 'Shared Albums Response: ' . $sharedAlbumsBody, ['app' => 'album_notifications']);
            $sharedAlbums = json_decode($sharedAlbumsBody, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->log(\Psr\Log\LogLevel::ERROR, 'Failed to fetch shared albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
            $sharedAlbums = [];
        }

        // Combine albums
        $albums = array_merge($myAlbums, $sharedAlbums);
        $this->logger->log(\Psr\Log\LogLevel::DEBUG, 'Combined Albums: ' . json_encode($albums), ['app' => 'album_notifications']);

        // Get selected albums from config
        $selectedAlbumsJson = $this->config->getUserValue($this->userId, 'album_notifications', 'selected_albums', '[]');
        $selected_albums = json_decode($selectedAlbumsJson, true);

        $parameters = [
            'user' => $displayName,
            'albums' => $albums,
            'selected_albums' => $selected_albums,
        ];
        return new TemplateResponse('album_notifications', 'settings', $parameters);
    }

    public function getSection() {
        return 'album_notifications';
    }

    public function getPriority() {
        return 10;
    }
}