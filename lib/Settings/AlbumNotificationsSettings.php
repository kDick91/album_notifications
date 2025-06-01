<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $db;
    private $appManager;
    private $logger;
    private $userId;

    public function __construct(
        IUserSession $userSession,
        IConfig $config,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->appManager = $appManager;
        $this->logger = $logger;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();
        $albums = [];

        // Try to get albums from different sources
        $albums = array_merge(
            $this->getMemoriesAlbums(),
            $this->getPhotosAlbums()
        );

        // Remove duplicates based on album ID
        $uniqueAlbums = [];
        $seenIds = [];
        foreach ($albums as $album) {
            if (!in_array($album['id'], $seenIds)) {
                $uniqueAlbums[] = $album;
                $seenIds[] = $album['id'];
            }
        }

        // Get selected albums from config
        $selectedAlbumsJson = $this->config->getUserValue($this->userId, 'album_notifications', 'selected_albums', '[]');
        $selected_albums = json_decode($selectedAlbumsJson, true) ?? [];

        $parameters = [
            'user' => $displayName,
            'albums' => $uniqueAlbums,
            'selected_albums' => $selected_albums,
        ];
        return new TemplateResponse('album_notifications', 'settings', $parameters);
    }

    private function getMemoriesAlbums(): array {
        $albums = [];

        // Check if Memories app is enabled
        if (!$this->appManager->isEnabledForUser('memories')) {
            $this->logger->log(LogLevel::DEBUG, 'Memories app is not enabled', ['app' => 'album_notifications']);
            return $albums;
        }

        try {
            // Query the memories_albums table directly
            $qb = $this->db->getQueryBuilder();
            $qb->select('album_id', 'name')
               ->from('memories_albums')
               ->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $albums[] = [
                    'id' => 'memories_' . $row['album_id'],
                    'name' => $row['name'] ?: 'Unnamed Album'
                ];
            }
            $result->closeCursor();

            $this->logger->log(LogLevel::DEBUG, 'Found ' . count($albums) . ' Memories albums', ['app' => 'album_notifications']);
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch Memories albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return $albums;
    }

    private function getPhotosAlbums(): array {
        $albums = [];

        // Check if Photos app is enabled
        if (!$this->appManager->isEnabledForUser('photos')) {
            $this->logger->log(LogLevel::DEBUG, 'Photos app is not enabled', ['app' => 'album_notifications']);
            return $albums;
        }

        try {
            // Try different possible table names for Photos app albums
            $possibleTables = ['photos_albums', 'oc_photos_albums', 'photos_album'];
            
            foreach ($possibleTables as $tableName) {
                try {
                    if ($this->tableExists($tableName)) {
                        $qb = $this->db->getQueryBuilder();
                        $qb->select('id', 'name', 'user')
                           ->from($tableName)
                           ->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)));

                        $result = $qb->executeQuery();
                        while ($row = $result->fetch()) {
                            $albums[] = [
                                'id' => 'photos_' . $row['id'],
                                'name' => $row['name'] ?: 'Unnamed Album'
                            ];
                        }
                        $result->closeCursor();
                        
                        $this->logger->log(LogLevel::DEBUG, 'Found ' . count($albums) . ' Photos albums from table: ' . $tableName, ['app' => 'album_notifications']);
                        break; // If we found a working table, stop trying others
                    }
                } catch (\Exception $e) {
                    // Continue to next table
                    continue;
                }
            }
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch Photos albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return $albums;
    }

    private function tableExists(string $tableName): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'))
               ->from($tableName)
               ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSection() {
        return 'album_notifications';
    }

    public function getPriority() {
        return 10;
    }
}