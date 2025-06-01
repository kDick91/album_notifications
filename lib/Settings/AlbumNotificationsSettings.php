<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\DB\IDBConnection; // Added for database access
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $connection; // Changed from IClientService to IDBConnection
    private $request;
    private $logger;
    private $userId;

    public function __construct(
        IUserSession $userSession,
        IConfig $config,
        IURLGenerator $urlGenerator,
        IDBConnection $connection, // Replaced IClientService with IDBConnection
        IRequest $request,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->connection = $connection; // Updated assignment
        $this->request = $request;
        $this->logger = $logger;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();

        // Fetch albums from the database
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.id', 'a.name')
           ->from('photos_albums', 'a')
           ->where($qb->expr()->eq('a.user', $qb->createNamedParameter($this->userId)))
           ->orWhere($qb->expr()->exists(function ($subQb) {
               $subQb->select('1')
                      ->from('photos_albums_collab', 'c')
                      ->where($subQb->expr()->eq('c.album_id', 'a.id'))
                      ->andWhere($subQb->expr()->eq('c.user_id', $subQb->createNamedParameter($this->userId)));
           }));

        try {
            $result = $qb->executeQuery();
            $albums = $result->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch albums from database: ' . $e->getMessage(), ['app' => 'album_notifications']);
            $albums = [];
        }

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