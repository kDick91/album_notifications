<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $clientService;
    private $request;
    private $logger;
    private $userId;

    public function __construct(
        IUserSession $userSession,
        IConfig $config,
        IURLGenerator $urlGenerator,
        IClientService $clientService,
        IRequest $request,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->clientService = $clientService;
        $this->request = $request;
        $this->logger = $logger;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();

        // Fetch albums from Photos app API
        $client = $this->clientService->newClient();
        $myAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.api.listAlbums', ['type' => 'my']);
        $sharedAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.api.listAlbums', ['type' => 'shared']);

        $sessionCookie = $this->request->getCookie('nc_session_id') ?? '';
        $options = [
            'headers' => [
                'Cookie' => 'nc_session_id=' . $sessionCookie,
                'X-Requested-With' => 'XMLHttpRequest'
            ],
        ];

        try {
            $myAlbumsResponse = $client->get($myAlbumsUrl, $options);
            $myAlbumsBody = $myAlbumsResponse->getBody();
            $this->logger->log(LogLevel::DEBUG, 'My Albums Response: ' . $myAlbumsBody, ['app' => 'album_notifications']);
            $myAlbums = json_decode($myAlbumsBody, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch my albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
            $myAlbums = [];
        }

        try {
            $sharedAlbumsResponse = $client->get($sharedAlbumsUrl, $options);
            $sharedAlbumsBody = $sharedAlbumsResponse->getBody();
            $this->logger->log(LogLevel::DEBUG, 'Shared Albums Response: ' . $sharedAlbumsBody, ['app' => 'album_notifications']);
            $sharedAlbums = json_decode($sharedAlbumsBody, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch shared albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
            $sharedAlbums = [];
        }

        // Normalize album data
        $albums = array_merge(
            array_map(function ($album) {
                return ['id' => $album['albumId'] ?? $album['id'] ?? '', 'name' => $album['name'] ?? $album['title'] ?? 'Unknown Album'];
            }, $myAlbums),
            array_map(function ($album) {
                return ['id' => $album['albumId'] ?? $album['id'] ?? '', 'name' => $album['name'] ?? $album['title'] ?? 'Unknown Album'];
            }, $sharedAlbums)
        );

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